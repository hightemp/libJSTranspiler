<?php

use PHPUnit\Framework\TestCase;
use libJSTranspiler\Transpiler;

class TranspilerTest extends TestCase
{
  public function testCheck_fnParse()
  {
    $oTranspiler = new Transpiler();
    
    $oRootNode = $oTranspiler->fnParse("var a = 1;");
    
    print_r($oRootNode);
  }
}
