<?php

namespace libJSTranspiler;

use libJSTranspiler\Parser;
use libJSTranspiler\SourceLocation;

class Node 
{
  public $sType;
  public $iStart;
  public $iEnd;
  public $oLoc;
  public $sSourceFile;
  public $aRange;
  public $sSourceType;
  public $oLabel;
  public $oTest;
  public $bAwait;
  public $aBody;
  public $oConsequent;
  public $oAlternate;
  public $oArgument;
  public $oDiscriminant;
  public $oBlock;
  public $oHandler;
  public $oFinalizer;
  public $oParam;
  public $oObject;
  public $oExpression;
  public $oInit;
  public $oUpdate;
  public $aCases;
  public $oLeft;
  public $oRight;
  public $aDeclarations;
  public $oId;
  public $sKind;
  public $bGenerator;
  public $bAsync;
  public $bStatic;
  public $oKey;
  public $bComputed;
  public $sValue;
  public $oValue;
  public $mValue;
  public $bSuperClass;
  public $oSource;
  public $oDeclaration;
  public $oSpecifiers;
  public $oLocal;
  public $oExported;
  public $sName;
  public $oImported;
  public $aProperties;
  public $aElements;
  public $sOperator;
  public $bMethod;
  public $bShorthand;
  public $aExpressions;
  public $bPrefix;
  public $oProperty;
  public $oCallee;
  public $aArguments;
  public $oTag;
  public $oQuasi;
  public $aQuasis;
  public $aRegex;
  public $sRaw;
  public $oMeta;
  public $bTail;
  public $aParams;
  
  public function __construct($oParser, $iPos, $oLoc)
  {
    $this->sType = "";
    $this->iStart = $iPos;
    $this->iEnd = 0;
    if ($oParser->aOptions['locations'])
      $this->oLoc = new SourceLocation($oParser, $oLoc);
    if ($oParser->aOptions['directSourceFile'])
      $this->oSourceFile = $oParser->aOptions['directSourceFile'];
    if ($oParser->aOptions['ranges'])
      $this->aRange = [$iPos, 0];
  }
}
