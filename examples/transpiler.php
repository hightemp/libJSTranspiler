<?php

include "../vendor/autoload.php";

use libJSTranspiler\Parser;
use libJSTranspiler\Transpiler;

$sInput = file_get_contents("test.js");
$oTree = Parser::fnParse($sInput);
$oTranspiler = new Transpiler();

echo $oTranspiler->fnGenerate($oTree);

