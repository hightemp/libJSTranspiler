<?php

include "../vendor/autoload.php";

use libJSTranspiler\Parser;
use libJSTranspiler\Transpiler;

$aOptions = [
  'source' => file_get_contents("test.js")
];

$oTranspiler = new Transpiler();

echo $oTranspiler->fnProcess();

