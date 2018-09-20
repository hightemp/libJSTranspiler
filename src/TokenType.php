<?php

namespace libJSTranspiler;

class TokenType
{
  public $sLabel;
  public $sKeyword;
  public $bBeforeExpr;
  public $bStartsExpr;
  public $bIsLoop;
  public $bIsAssign;
  public $bPrefix;
  public $bPostfix;
  public $iBinop;
  public $fnUpdateContext;
  
  public function __construct($sLabel, $aConf=[])
  {
    $aDefaultConf = [
      'keyword' => '',
      'beforeExpr' => false,
      'startsExpr' => false,
      'isLoop' => false,
      'isAssign' => false,
      'prefix' => false,
      'postfix' => false,
      'binop' => null,
    ];
    
    $aConf = array_merge($aDefaultConf, $aConf);
    
    $this->sLabel = $sLabel;
    $this->sKeyword = $aConf['keyword'];
    $this->bBeforeExpr = !!$aConf['beforeExpr'];
    $this->bStartsExpr = !!$aConf['startsExpr'];
    $this->bIsLoop = !!$aConf['isLoop'];
    $this->bIsAssign = !!$aConf['isAssign'];
    $this->bPrefix = !!$aConf['prefix'];
    $this->bPostfix = !!$aConf['postfix'];
    $this->iBinop = $aConf['binop'];
    $this->fnUpdateContext = null;  
  }
}