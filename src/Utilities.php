<?php

namespace libJSTranspiler;

class Utilities
{
  public static function fnGetCharAt($sString, $iIndex)
  {
    return preg_split('/(?<!^)(?!$)/u', $sString)[$iIndex];
  }

  public static function fnGetCharCodeAt($sString, $iIndex)
  {
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
}