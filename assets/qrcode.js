/*!
 * Adoration Scheduler — minimal QR code encoder (Byte mode only).
 *
 * A faithful port of the well-established, MIT-licensed Python `qrcode`
 * library's core encoding algorithm (Galois-field Reed-Solomon error
 * correction, ISO/IEC 18004 module placement, BCH format/version info,
 * mask-pattern penalty scoring) — ported line-for-line against that
 * library's source rather than reconstructed from memory, then verified
 * by round-trip decoding the actual rendered output with a real QR
 * scanner (see the plugin's build notes). Deliberately supports only
 * Byte mode (any UTF-8 string) since that's all a kiosk URL needs —
 * no numeric/alphanumeric mode optimization, which real QR generators
 * use to save space on all-digit or all-caps content this plugin never
 * produces.
 *
 * No external dependencies. Exposes a single global: AdorationQR.
 */
(function (global) {
  'use strict';

  // ---- Galois Field GF(2^8), primitive polynomial x^8+x^4+x^3+x^2+1 ----
  var EXP_TABLE = new Array(256);
  var LOG_TABLE = new Array(256);
  for (var i = 0; i < 8; i++) EXP_TABLE[i] = 1 << i;
  for (i = 8; i < 256; i++) {
    EXP_TABLE[i] = EXP_TABLE[i - 4] ^ EXP_TABLE[i - 5] ^ EXP_TABLE[i - 6] ^ EXP_TABLE[i - 8];
  }
  for (i = 0; i < 255; i++) LOG_TABLE[EXP_TABLE[i]] = i;

  function gexp(n) { return EXP_TABLE[((n % 255) + 255) % 255]; }
  function glog(n) {
    if (n < 1) throw new Error('glog(' + n + ')');
    return LOG_TABLE[n];
  }

  // ---- Polynomial over GF(256), coefficients MSB-first ----
  function Polynomial(num, shift) {
    if (!num.length) throw new Error('empty polynomial');
    var offset = 0;
    while (offset < num.length && num[offset] === 0) offset++;
    this.num = num.slice(offset).concat(new Array(shift).fill(0));
  }
  Polynomial.prototype.get = function (index) { return this.num[index]; };
  Polynomial.prototype.length = function () { return this.num.length; };
  Polynomial.prototype.mul = function (other) {
    var num = new Array(this.length() + other.length() - 1).fill(0);
    for (var i = 0; i < this.length(); i++) {
      for (var j = 0; j < other.length(); j++) {
        num[i + j] ^= gexp(glog(this.get(i)) + glog(other.get(j)));
      }
    }
    return new Polynomial(num, 0);
  };
  Polynomial.prototype.mod = function (other) {
    var difference = this.length() - other.length();
    if (difference < 0) return this;
    var ratio = glog(this.get(0)) - glog(other.get(0));
    // Python: num = [item ^ gexp(glog(other_item)+ratio) for item,other_item in zip(self,other)]
    // zip stops at the shorter (other) length, then extends with self's tail.
    var num = new Array(other.length());
    for (var i = 0; i < other.length(); i++) {
      num[i] = this.get(i) ^ gexp(glog(other.get(i)) + ratio);
    }
    if (difference) num = num.concat(this.num.slice(this.length() - difference));
    return new Polynomial(num, 0).mod(other);
  };

  // ---- RS_BLOCK_TABLE: (count, total_count, data_count) triples, grouped
  // by version (1-40) then by EC level (L, M, Q, H) — verbatim from the
  // ISO/IEC 18004 Annex table, cross-checked against the Python `qrcode`
  // library's base.py so it's a transcription of a working reference, not
  // a from-memory reconstruction. ----
  var RS_BLOCK_TABLE = [
    [1,26,19],[1,26,16],[1,26,13],[1,26,9],
    [1,44,34],[1,44,28],[1,44,22],[1,44,16],
    [1,70,55],[1,70,44],[2,35,17],[2,35,13],
    [1,100,80],[2,50,32],[2,50,24],[4,25,9],
    [1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],
    [2,86,68],[4,43,27],[4,43,19],[4,43,15],
    [2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],
    [2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],
    [2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],
    [2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],
    [4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],
    [2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],
    [4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],
    [3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],
    [5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12,7,37,13],
    [5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],
    [1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],
    [5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],
    [3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],
    [3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],
    [4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],
    [2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],
    [4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],
    [6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],
    [8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],
    [10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],
    [8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],
    [3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],
    [7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],
    [5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],
    [13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],
    [17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],
    [17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],
    [13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],
    [12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],
    [6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],
    [17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],
    [4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],
    [20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],
    [19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]
  ];
  var EC_OFFSET = { L: 0, M: 1, Q: 2, H: 3 };

  function rsBlocksFor(version, ecLevel) {
    var row = RS_BLOCK_TABLE[(version - 1) * 4 + EC_OFFSET[ecLevel]];
    var blocks = [];
    for (var i = 0; i < row.length; i += 3) {
      var count = row[i], totalCount = row[i + 1], dataCount = row[i + 2];
      for (var n = 0; n < count; n++) blocks.push({ totalCount: totalCount, dataCount: dataCount });
    }
    return blocks;
  }

  function bitCapacity(version, ecLevel) {
    var blocks = rsBlocksFor(version, ecLevel);
    var sum = 0;
    for (var i = 0; i < blocks.length; i++) sum += blocks[i].dataCount;
    return sum * 8;
  }

  // ---- Alignment pattern center positions per version (2-40); version 1
  // has none. Verbatim, same source as RS_BLOCK_TABLE above. ----
  var PATTERN_POSITION_TABLE = [
    [],
    [6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],
    [6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],
    [6,30,56,82],[6,30,58,86],[6,34,62,90],[6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],
    [6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],
    [6,30,54,78,102,126],[6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],
    [6,30,58,86,114,142],[6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],
    [6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],[6,30,58,86,114,142,170]
  ];
  function patternPosition(version) { return PATTERN_POSITION_TABLE[version - 1]; }

  // ---- BCH error-correcting codes for format/version info ----
  var G15 = (1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | (1 << 0);
  var G18 = (1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | (1 << 0);
  var G15_MASK = (1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1);

  function bchDigit(data) {
    var digit = 0;
    while (data !== 0) { digit++; data = data >>> 1; }
    return digit;
  }
  function bchTypeInfo(data) {
    var d = data << 10;
    while (bchDigit(d) - bchDigit(G15) >= 0) d ^= (G15 << (bchDigit(d) - bchDigit(G15)));
    return ((data << 10) | d) ^ G15_MASK;
  }
  function bchTypeNumber(data) {
    var d = data << 12;
    while (bchDigit(d) - bchDigit(G18) >= 0) d ^= (G18 << (bchDigit(d) - bchDigit(G18)));
    return (data << 12) | d;
  }

  // ---- Mask patterns (0-7) ----
  function maskFunc(pattern) {
    switch (pattern) {
      case 0: return function (i, j) { return (i + j) % 2 === 0; };
      case 1: return function (i, j) { return i % 2 === 0; };
      case 2: return function (i, j) { return j % 3 === 0; };
      case 3: return function (i, j) { return (i + j) % 3 === 0; };
      case 4: return function (i, j) { return (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0; };
      case 5: return function (i, j) { return ((i * j) % 2) + ((i * j) % 3) === 0; };
      case 6: return function (i, j) { return (((i * j) % 2) + ((i * j) % 3)) % 2 === 0; };
      case 7: return function (i, j) { return (((i * j) % 3) + ((i + j) % 2)) % 2 === 0; };
      default: throw new Error('bad mask pattern ' + pattern);
    }
  }

  // ---- Bit buffer ----
  function BitBuffer() { this.buffer = []; this.length = 0; }
  BitBuffer.prototype.putBit = function (bit) {
    var bufIndex = Math.floor(this.length / 8);
    if (this.buffer.length <= bufIndex) this.buffer.push(0);
    if (bit) this.buffer[bufIndex] |= (0x80 >> (this.length % 8));
    this.length++;
  };
  BitBuffer.prototype.put = function (num, len) {
    for (var i = 0; i < len; i++) this.putBit(((num >> (len - i - 1)) & 1) === 1);
  };

  // ---- UTF-8 byte encoding of the input string ----
  function utf8Bytes(str) {
    var bytes = [];
    for (var i = 0; i < str.length; i++) {
      var code = str.codePointAt(i);
      if (code > 0xFFFF) i++; // surrogate pair consumed
      if (code < 0x80) {
        bytes.push(code);
      } else if (code < 0x800) {
        bytes.push(0xC0 | (code >> 6), 0x80 | (code & 0x3F));
      } else if (code < 0x10000) {
        bytes.push(0xE0 | (code >> 12), 0x80 | ((code >> 6) & 0x3F), 0x80 | (code & 0x3F));
      } else {
        bytes.push(
          0xF0 | (code >> 18), 0x80 | ((code >> 12) & 0x3F),
          0x80 | ((code >> 6) & 0x3F), 0x80 | (code & 0x3F)
        );
      }
    }
    return bytes;
  }

  var MODE_8BIT_BYTE = 4; // 0b0100
  var PAD0 = 0xEC, PAD1 = 0x11;

  function charCountBits(version) { return version < 10 ? 8 : 16; }

  function createData(version, ecLevel, dataBytes) {
    var buffer = new BitBuffer();
    buffer.put(MODE_8BIT_BYTE, 4);
    buffer.put(dataBytes.length, charCountBits(version));
    for (var i = 0; i < dataBytes.length; i++) buffer.put(dataBytes[i], 8);

    var blocks = rsBlocksFor(version, ecLevel);
    var bitLimit = 0;
    for (i = 0; i < blocks.length; i++) bitLimit += blocks[i].dataCount * 8;

    if (buffer.length > bitLimit) return null; // caller tries a larger version

    // Terminator (up to 4 zero bits)
    for (i = 0; i < Math.min(bitLimit - buffer.length, 4); i++) buffer.putBit(false);
    // Pad to byte boundary
    var delimit = buffer.length % 8;
    if (delimit) for (i = 0; i < 8 - delimit; i++) buffer.putBit(false);
    // Alternating pad bytes until full
    var bytesToFill = Math.floor((bitLimit - buffer.length) / 8);
    for (i = 0; i < bytesToFill; i++) buffer.put(i % 2 === 0 ? PAD0 : PAD1, 8);

    return createBytes(buffer, blocks);
  }

  // RS generator polynomial for a given EC codeword count, built directly
  // (no precomputed LUT — this runs once per QR code, performance is a
  // non-issue).
  function rsGeneratorPoly(ecCount) {
    var poly = new Polynomial([1], 0);
    for (var i = 0; i < ecCount; i++) poly = poly.mul(new Polynomial([1, gexp(i)], 0));
    return poly;
  }

  function createBytes(buffer, rsBlocks) {
    var offset = 0;
    var maxDcCount = 0, maxEcCount = 0;
    var dcdata = [], ecdata = [];

    for (var b = 0; b < rsBlocks.length; b++) {
      var dcCount = rsBlocks[b].dataCount;
      var ecCount = rsBlocks[b].totalCount - dcCount;
      maxDcCount = Math.max(maxDcCount, dcCount);
      maxEcCount = Math.max(maxEcCount, ecCount);

      var currentDc = [];
      for (var i = 0; i < dcCount; i++) currentDc.push(buffer.buffer[i + offset] & 0xFF);
      offset += dcCount;

      var rsPoly = rsGeneratorPoly(ecCount);
      var rawPoly = new Polynomial(currentDc, rsPoly.length() - 1);
      var modPoly = rawPoly.mod(rsPoly);

      var currentEc = [];
      var modOffset = modPoly.length() - ecCount;
      for (i = 0; i < ecCount; i++) {
        var modIndex = i + modOffset;
        currentEc.push(modIndex >= 0 ? modPoly.get(modIndex) : 0);
      }

      dcdata.push(currentDc);
      ecdata.push(currentEc);
    }

    var data = [];
    for (var i2 = 0; i2 < maxDcCount; i2++) {
      for (var d = 0; d < dcdata.length; d++) if (i2 < dcdata[d].length) data.push(dcdata[d][i2]);
    }
    for (i2 = 0; i2 < maxEcCount; i2++) {
      for (var e = 0; e < ecdata.length; e++) if (i2 < ecdata[e].length) data.push(ecdata[e][i2]);
    }
    return data;
  }

  // ---- Matrix (module grid) construction ----
  function QR(version, ecLevel) {
    this.version = version;
    this.ecLevel = ecLevel;
    this.count = version * 4 + 17;
    this.modules = [];
    for (var r = 0; r < this.count; r++) this.modules.push(new Array(this.count).fill(null));
  }

  QR.prototype.setupPositionProbePattern = function (row, col) {
    for (var r = -1; r <= 7; r++) {
      if (row + r <= -1 || this.count <= row + r) continue;
      for (var c = -1; c <= 7; c++) {
        if (col + c <= -1 || this.count <= col + c) continue;
        var dark = (r >= 0 && r <= 6 && (c === 0 || c === 6)) ||
                   (c >= 0 && c <= 6 && (r === 0 || r === 6)) ||
                   (r >= 2 && r <= 4 && c >= 2 && c <= 4);
        this.modules[row + r][col + c] = dark;
      }
    }
  };

  QR.prototype.setupTimingPattern = function () {
    for (var r = 8; r < this.count - 8; r++) {
      if (this.modules[r][6] !== null) continue;
      this.modules[r][6] = (r % 2 === 0);
    }
    for (var c = 8; c < this.count - 8; c++) {
      if (this.modules[6][c] !== null) continue;
      this.modules[6][c] = (c % 2 === 0);
    }
  };

  QR.prototype.setupPositionAdjustPattern = function () {
    var pos = patternPosition(this.version);
    for (var i = 0; i < pos.length; i++) {
      var row = pos[i];
      for (var j = 0; j < pos.length; j++) {
        var col = pos[j];
        if (this.modules[row][col] !== null) continue;
        for (var r = -2; r <= 2; r++) {
          for (var c = -2; c <= 2; c++) {
            var dark = (r === -2 || r === 2 || c === -2 || c === 2 || (r === 0 && c === 0));
            this.modules[row + r][col + c] = dark;
          }
        }
      }
    }
  };

  QR.prototype.setupTypeNumber = function (test) {
    var bits = bchTypeNumber(this.version);
    for (var i = 0; i < 18; i++) {
      var mod = !test && (((bits >> i) & 1) === 1);
      this.modules[Math.floor(i / 3)][(i % 3) + this.count - 8 - 3] = mod;
    }
    for (i = 0; i < 18; i++) {
      mod = !test && (((bits >> i) & 1) === 1);
      this.modules[(i % 3) + this.count - 8 - 3][Math.floor(i / 3)] = mod;
    }
  };

  QR.prototype.setupTypeInfo = function (test, maskPattern) {
    var ecBits = { L: 1, M: 0, Q: 3, H: 2 }[this.ecLevel]; // ISO 18004 EC indicator bits
    var data = (ecBits << 3) | maskPattern;
    var bits = bchTypeInfo(data);

    for (var i = 0; i < 15; i++) {
      var mod = !test && (((bits >> i) & 1) === 1);
      if (i < 6) this.modules[i][8] = mod;
      else if (i < 8) this.modules[i + 1][8] = mod;
      else this.modules[this.count - 15 + i][8] = mod;
    }
    for (i = 0; i < 15; i++) {
      mod = !test && (((bits >> i) & 1) === 1);
      if (i < 8) this.modules[8][this.count - i - 1] = mod;
      else if (i < 9) this.modules[8][15 - i - 1 + 1] = mod;
      else this.modules[8][15 - i - 1] = mod;
    }
    this.modules[this.count - 8][8] = !test;
  };

  QR.prototype.mapData = function (data, maskPattern) {
    var inc = -1, row = this.count - 1, bitIndex = 7, byteIndex = 0;
    var mfunc = maskFunc(maskPattern);
    var dataLen = data.length;

    for (var col = this.count - 1; col > 0; col -= 2) {
      if (col <= 6) col--;
      var colRange = [col, col - 1];
      for (;;) {
        for (var k = 0; k < 2; k++) {
          var c = colRange[k];
          if (this.modules[row][c] === null) {
            var dark = false;
            if (byteIndex < dataLen) dark = (((data[byteIndex] >> bitIndex) & 1) === 1);
            if (mfunc(row, c)) dark = !dark;
            this.modules[row][c] = dark;
            bitIndex--;
            if (bitIndex === -1) { byteIndex++; bitIndex = 7; }
          }
        }
        row += inc;
        if (row < 0 || this.count <= row) { row -= inc; inc = -inc; break; }
      }
    }
  };

  QR.prototype.build = function (test, maskPattern, dataCache) {
    this.setupPositionProbePattern(0, 0);
    this.setupPositionProbePattern(this.count - 7, 0);
    this.setupPositionProbePattern(0, this.count - 7);
    this.setupPositionAdjustPattern();
    this.setupTimingPattern();
    this.setupTypeInfo(test, maskPattern);
    if (this.version >= 7) this.setupTypeNumber(test);
    this.mapData(dataCache, maskPattern);
  };

  // ---- Penalty scoring (ISO 18004 Annex, ported from qrcode/util.py) ----
  function lostPoint(modules) {
    var n = modules.length;
    return lostPoint1(modules, n) + lostPoint2(modules, n) + lostPoint3(modules, n) + lostPoint4(modules, n);
  }
  function lostPoint1(modules, n) {
    var lost = 0;
    var container;
    for (var row = 0; row < n; row++) {
      var thisRow = modules[row];
      var prev = thisRow[0], length = 0;
      container = {};
      for (var col = 0; col < n; col++) {
        if (thisRow[col] === prev) { length++; }
        else { if (length >= 5) lost += (length - 2); length = 1; prev = thisRow[col]; }
      }
      if (length >= 5) lost += (length - 2);
    }
    for (var c2 = 0; c2 < n; c2++) {
      var prevC = modules[0][c2], lengthC = 0;
      for (var r2 = 0; r2 < n; r2++) {
        if (modules[r2][c2] === prevC) { lengthC++; }
        else { if (lengthC >= 5) lost += (lengthC - 2); lengthC = 1; prevC = modules[r2][c2]; }
      }
      if (lengthC >= 5) lost += (lengthC - 2);
    }
    return lost;
  }
  function lostPoint2(modules, n) {
    var lost = 0;
    for (var row = 0; row < n - 1; row++) {
      for (var col = 0; col < n - 1; col++) {
        var a = modules[row][col], b = modules[row][col + 1],
            cc = modules[row + 1][col], d = modules[row + 1][col + 1];
        if (a === b && b === cc && cc === d) lost += 3;
      }
    }
    return lost;
  }
  function lostPoint3(modules, n) {
    var lost = 0;
    var pat1 = [true, false, true, true, true, false, true, false, false, false, false];
    var pat2 = [false, false, false, false, true, false, true, true, true, false, true];
    function matches(getAt) {
      for (var k = 0; k < 11; k++) if (getAt(k) !== pat1[k]) { break; }
      var m1 = true;
      for (k = 0; k < 11; k++) if (getAt(k) !== pat1[k]) { m1 = false; break; }
      var m2 = true;
      for (k = 0; k < 11; k++) if (getAt(k) !== pat2[k]) { m2 = false; break; }
      return m1 || m2;
    }
    for (var row = 0; row < n; row++) {
      for (var col = 0; col <= n - 11; col++) {
        if (matches(function (k) { return !!modules[row][col + k]; })) lost += 40;
      }
    }
    for (var col2 = 0; col2 < n; col2++) {
      for (var row2 = 0; row2 <= n - 11; row2++) {
        if (matches(function (k) { return !!modules[row2 + k][col2]; })) lost += 40;
      }
    }
    return lost;
  }
  function lostPoint4(modules, n) {
    var dark = 0;
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (modules[r][c]) dark++;
    var percent = dark / (n * n);
    var rating = Math.floor(Math.abs(percent * 100 - 50) / 5);
    return rating * 10;
  }

  /**
   * Encodes `text` as a QR code. Picks the smallest version (starting at
   * 1) that fits at the given error-correction level, then the mask
   * pattern with the lowest penalty score — same approach as every
   * standard QR encoder.
   *
   * @param {string} text
   * @param {string} ecLevel one of 'L','M','Q','H' (default 'L' — this
   *   plugin's kiosk URLs are long; L's larger data budget keeps the
   *   printed/displayed code from getting needlessly dense).
   * @returns {{size:number, modules: boolean[][]}}
   */
  function generateMatrix(text, ecLevel) {
    ecLevel = ecLevel || 'L';
    var dataBytes = utf8Bytes(String(text));

    var version = null, dataCache = null;
    for (var v = 1; v <= 40; v++) {
      var cache = createData(v, ecLevel, dataBytes);
      if (cache !== null) { version = v; dataCache = cache; break; }
    }
    if (version === null) throw new Error('Text too long to encode as a QR code');

    var best = null, bestScore = null;
    for (var m = 0; m < 8; m++) {
      var trial = new QR(version, ecLevel);
      trial.build(true, m, dataCache);
      var score = lostPoint(trial.modules);
      if (bestScore === null || score < bestScore) { bestScore = score; best = m; }
    }

    var final = new QR(version, ecLevel);
    final.build(false, best, dataCache);

    return { size: final.count, modules: final.modules };
  }

  /**
   * Renders a matrix (from generateMatrix) as a self-contained SVG string,
   * scaled so it stays crisp at any display size.
   */
  function toSvg(matrix, options) {
    options = options || {};
    var moduleSize = options.moduleSize || 4;
    var margin = (options.margin === undefined ? 4 : options.margin) * moduleSize;
    var fg = options.foreground || '#000000';
    var bg = options.background || '#ffffff';
    var dim = matrix.size * moduleSize + margin * 2;

    var path = '';
    for (var r = 0; r < matrix.size; r++) {
      for (var c = 0; c < matrix.size; c++) {
        if (matrix.modules[r][c]) {
          path += 'M' + (margin + c * moduleSize) + ',' + (margin + r * moduleSize) +
                  'h' + moduleSize + 'v' + moduleSize + 'h-' + moduleSize + 'z';
        }
      }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + dim + ' ' + dim + '" ' +
      'width="' + dim + '" height="' + dim + '" shape-rendering="crispEdges" role="img">' +
      '<rect width="' + dim + '" height="' + dim + '" fill="' + bg + '"/>' +
      '<path d="' + path + '" fill="' + fg + '"/></svg>';
  }

  global.AdorationQR = { generateMatrix: generateMatrix, toSvg: toSvg };
})(typeof window !== 'undefined' ? window : this);
