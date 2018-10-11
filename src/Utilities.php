<?php

namespace libJSTranspiler;

class Utilities
{
  public static function fnGetCharAt($sString, $iIndex)
  {
    //return preg_split('/(?<!^)(?!$)/u', $sString)[$iIndex];
    // FIXME:
    return preg_split('/\X{1}\K/u', $sString)[$iIndex];
  }

  public static function fnGetCharCodeAt($sString, $iIndex)
  {
    // FIXME:
    return self::fnUniord(self::fnGetCharAt($sString, $iIndex));
  }
  
  // code point to UTF-8 string
  public static function fnUnichr($i) 
  {
      return iconv('UCS-4LE', 'UTF-8', pack('V', $i));
  }

  // UTF-8 string to code point
  public static function fnUniord($s) 
  {
      return unpack('V', iconv('UTF-8', 'UCS-4LE', $s))[1];
  }

  const ESC_CHARS = [
    '\t' => '\\t',
    '\n' => '\\n',
    '\f' => '\\f',
    '\r' => '\\r',
    '"'  => '\\"',
    '$'  => '\\$',
    '\\' => '\\\\'
  ];
  
  public static function toHex(code, prefix) {
    var hex = code.toString(16).toUpperCase();
    if (hex.length === 1) {
      hex = '0' + hex;
    }
    if (prefix) {
      hex = prefix + hex;
    }
    return hex;
  }

  public static function toOctet(codePoint, shift, prefix) {
    return toHex(((codePoint >> shift) & 0x3F) | 0x80, prefix);
  }

  //encode unicode character to a set of escaped octets like \xC2\xA9
  public static function encodeChar(ch, prefix) {
    var code = ch.charCodeAt(0);
    if ((code & 0xFFFFFF80) == 0) { // 1-byte sequence
      return toHex(code, prefix);
    }
    var result = '';
    if ((code & 0xFFFFF800) == 0) { // 2-byte sequence
      result = toHex(((code >> 6) & 0x1F) | 0xC0, prefix);
    }
    else if ((code & 0xFFFF0000) == 0) { // 3-byte sequence
      result = toHex(((code >> 12) & 0x0F) | 0xE0, prefix);
      result += toOctet(code, 6, prefix);
    }
    else if ((code & 0xFFE00000) == 0) { // 4-byte sequence
      result = toHex(((code >> 18) & 0x07) | 0xF0, prefix);
      result += toOctet(code, 12, prefix);
      result += toOctet(code, 6, prefix);
    }
    result += toHex((code & 0x3F) | 0x80, prefix);
    return result;
  }
  
  public static function fnEcodeString($sString) 
  {
    $sString = preg_replace_callback(
      "/[\\\"\$\x00-\x1F\x{007F}-\x{FFFF}/", 
      function ($sCh) {
        return (isset(self::ESC_CHARS[$sCh])) ? self::ESC_CHARS[$sCh] : self::fnEncodeChar($sCh, '\\x');
      },
      $sString
    );
    return '"'.$sString.'"';
  }
}