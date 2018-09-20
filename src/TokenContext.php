<?php

namespace libJSTranspiler;

class TokenContext 
{
  public $sToken;
  public $bIsExpr;
  public $bPreserveSpace;
  public $fnOverride;
  public $bGenerator;

  public function __construct(
          $sToken, 
          $bIsExpr=false, 
          $bPreserveSpace=false, 
          $fnOverride=null, 
          $bGenerator=false) 
  {
    $this->sToken = $sToken;
    $this->bIsExpr = !!$bIsExpr;
    $this->bPreserveSpace = !!$bPreserveSpace;
    $this->fnOverride = $fnOverride;
    $this->bGenerator = !!$bGenerator;
  }
}

class TokenContextTypes 
{
  public static $aTypes = [];
  
  public static function fnAddArray($aArray)
  {
    foreach ($aArray as $aItem) {
      $oRefClass = new ReflectionClass('TokenContext');
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
