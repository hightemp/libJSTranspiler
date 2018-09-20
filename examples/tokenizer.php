<?php

include "../vendor/autoload.php";

use libJSTranspiler\Parser;

$aOptions = [];
$sInput = file_get_contents("example.js");
$oParser = Parser::fnParse($sInput, $aOptions);