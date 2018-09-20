<?php

namespace libJSTranspiler;

use libJSTranspiler\TokenContext;
use ReflectionClass;

class TokenContextTypes 
{
  public static $aTypes = [];
  
  public static function fnAddArray($aArray)
  {
    foreach ($aArray as $aItem) {
      $oRefClass = new ReflectionClass('libJSTranspiler\TokenContext');
      $aArguments = array_slice($aItem, 1);
      self::$aTypes[$aItem[0]] = $oRefClass->newInstanceArgs($aArguments);
    }
  }
}

TokenContextTypes::fnAddArray([
  ["b_stat", "{", false],
  ["b_expr", "{", true],
  ["b_tmpl", '${', false],
  ["p_stat", "(", false],
  ["p_expr", "(", true],
  ["q_tmpl", "`", true, true, function($p) { return $p->fnTryReadTemplateToken(); } ],
  ["f_stat", "function", false],
  ["f_expr", "function", true],
  ["f_expr_gen", "function", true, false, null, true],
  ["f_gen", "function", false, false, null, true],
]);
