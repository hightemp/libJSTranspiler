<?php

namespace libJSTranspiler;

class Whitespace
{
  const lineBreak = "/\r\n?|\n|\x{2028}|\x{2029}/u";

  public static function fnIsNewLine($iCode, $bEcma2019String) 
  {
    return $iCode === 10 
      || $iCode === 13 
      || (!$bEcma2019String && ($iCode === 0x2028 || $iCode === 0x2029));
  }

  const nonASCIIwhitespace = "/[\x{1680}\x{180e}\x{2000}-\x{200a}\x{202f}\x{205f}\x{3000}\x{feff}]/u";
  const skipWhiteSpace = "/(?:\s|\/\/.*|\/\*[\W\w]*?\*\/)*/u";
}