<?php

namespace libJSTranspiler;

class Utilities
{
  // code point to UTF-8 string
  function fnUnichr($i) 
  {
      return iconv('UCS-4LE', 'UTF-8', pack('V', $i));
  }

  // UTF-8 string to code point
  function fnUniord($s) 
  {
      return unpack('V', iconv('UTF-8', 'UCS-4LE', $s))[1];
  }
}