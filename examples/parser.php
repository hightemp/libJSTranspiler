<?php

include "../vendor/autoload.php";

use libJSTranspiler\Parser;

$sInput = file_get_contents("example.js");
$oTree = Parser::fnParse($sInput);

var_export($oTree);