<?php

use PHPUnit\Framework\TestCase;
use libJSTranspiler\Utilities;

class UtilitiesTest extends TestCase
{
  public function testCheck_fnEcodeString()
  {
    $sResult = Utilities::fnEcodeString("Örnsköldsvik");
    
    $this->assertFalse($sResult == '\xC3\x96rnsk\xC3\xB6ldsvik');
  }
}
