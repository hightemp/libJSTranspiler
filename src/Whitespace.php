<?php

namespace libJSTranspiler;

class Whitespace
{
  const lineBreak = "/\r\n?|\n|\u2028|\u2029/";
  const lineBreakG = self::lineBreak."g";

  public static function fnIsNewLine($iCode, $bEcma2019String) 
  {
    return $iCode === 10 
      || $iCode === 13 
      || (!$bEcma2019String && ($iCode === 0x2028 || $iCode === 0x2029));
  }

  const nonASCIIwhitespace = "/[\u1680\u180e\u2000-\u200a\u202f\u205f\u3000\ufeff]/";
  const skipWhiteSpace = "/(?:\s|\/\/.*|\/\*[^]*?\*\/)*/g";
}