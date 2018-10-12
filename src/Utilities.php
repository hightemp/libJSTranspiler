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
  
  public static function fnToHex($iCode, $sPrefix) 
  {
    $sHex = strtoupper(bin2hex($iCode));
    if (strlen($sHex)==1) {
      $sHex = '0' . $sHex;
    }
    if ($sPrefix) {
      $sHex = $sPrefix . $sHex;
    }
    return $sHex;
  }

  public static function fnToOctet($iCodePoint, $iShift, $sPrefix) 
  {
    return self::fnToHex((($iCodePoint >> $iShift) & 0x3F) | 0x80, $sPrefix);
  }

  //encode unicode character to a set of escaped octets like \xC2\xA9
  public static function fnEncodeChar($sCh, $sPrefix) 
  {
    $iCode = self::fnUniord($sCh);
    if (($iCode & 0xFFFFFF80) == 0) { // 1-byte sequence
      return self::fnToHex($iCode, $sPrefix);
    }
    $sResult = '';
    if (($iCode & 0xFFFFF800) == 0) { // 2-byte sequence
      $sResult = self::fnToHex((($iCode >> 6) & 0x1F) | 0xC0, $sPrefix);
    }
    else if (($iCode & 0xFFFF0000) == 0) { // 3-byte sequence
      $sResult = self::fnToHex((($iCode >> 12) & 0x0F) | 0xE0, $sPrefix);
      $sResult .= self::fnToOctet($iCode, 6, $sPrefix);
    }
    else if (($iCode & 0xFFE00000) == 0) { // 4-byte sequence
      $sResult = self::fnToHex((($iCode >> 18) & 0x07) | 0xF0, $sPrefix);
      $sResult .= self::fnToOctet($iCode, 12, $sPrefix);
      $sResult .= self::fnToOctet($iCode, 6, $sPrefix);
    }
    $sResult .= self::fnToHex(($iCode & 0x3F) | 0x80, $sPrefix);
    return $sResult;
  }
  
  public static function fnEcodeString($sString) 
  {
    $sString = preg_replace_callback(
      "/[\\\"\$\x{00}-\x{1F}\x{007F}-\x{FFFF}]/u", 
      function ($sCh) {
        return (Utilities::ESC_CHARS[$sCh]) ? Utilities::ESC_CHARS[$sCh] : Utilities::fnEncodeChar($sCh, '\\x');
      },
      $sString
    );
    return '"'.$sString.'"';
  }
}