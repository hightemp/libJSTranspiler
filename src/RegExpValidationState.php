<?php

namespace libJSTranspiler;

use libJSTranspiler\Utilities;

class RegExpValidationState 
{
  public $oParser;
  public $sValidFlags;
  public $sSource;
  public $sFlags;
  public $iStart;
  public $bSwitchU;
  public $bSwitchN;
  public $iPos;
  public $iLastIntValue;
  public $sLastStringValue;
  public $bLastAssertionIsQuantifiable;
  public $iNumCapturingParens;
  public $iMaxBackReference;
  public $aGroupNames;
  public $aBackReferenceNames;
  
  public function __construct($oParser) 
  {
    $this->oParser = $oParser;
    $this->sValidFlags = "gim".($oParser->aOptions['ecmaVersion'] >= 6 ? "uy" : "").($oParser->aOptions['ecmaVersion'] >= 9 ? "s" : "");
    $this->sSource = "";
    $this->sFlags = "";
    $this->iStart = 0;
    $this->bSwitchU = false;
    $this->bSwitchN = false;
    $this->iPos = 0;
    $this->iLastIntValue = 0;
    $this->sLastStringValue = "";
    $this->bLastAssertionIsQuantifiable = false;
    $this->iNumCapturingParens = 0;
    $this->iMaxBackReference = 0;
    $this->aGroupNames = [];
    $this->aBackReferenceNames = [];
  }

  public function fnReset($iStart, $sPattern, $sFlags) 
  {
    $bUnicode = mb_strpos($sFlags, "u") !== false;
    $this->iStart = $iStart;
    $this->sSource = $sPattern;
    $this->sFlags = $sFlags;
    $this->bSwitchU = $bUnicode && $this->oParser->aOptions['ecmaVersion'] >= 6;
    $this->bSwitchN = $bUnicode && $this->oParser->aOptions['ecmaVersion'] >= 9;
  }

  public function fnRaise($sMessage) 
  {
    $this->oParser->fnRaiseRecoverable($this->iStart, "Invalid regular expression: /{$this->sSource}/: {$sMessage}");
  }

  // If u flag is given, this returns the code point at the index (it combines a surrogate pair).
  // Otherwise, this returns the code unit of the index (can be a part of a surrogate pair).
  public function fnAt($iI) 
  {
    $sS = $this->sSource;
    $iL = mb_strlen($sS);
    if ($iI >= $iL) {
      return -1;
    }
    $iC = Utilities::fnGetCharCodeAt($sS, $iI);
    if (!$this->bSwitchU || $iC <= 0xD7FF || $iC >= 0xE000 || $iI + 1 >= $iL) {
      return $iC;
    }
    return ($iC << 10) + Utilities::fnGetCharCodeAt($sS, $iI + 1) - 0x35FDC00;
  }

  public function fnNextIndex($iI) 
  {
    $sS = $this->sSource;
    $iL = mb_strlen($sS);
    if ($iI >= $iL) {
      return $iL;
    }
    $iC = Utilities::fnGetCharCodeAt($sS, $iI);
    if (!$this->bSwitchU || $iC <= 0xD7FF || $iC >= 0xE000 || $iI + 1 >= $iL) {
      return $iI + 1;
    }
    return $iI + 2;
  }

  public function fnCurrent() 
  {
    return $this->fnAt($this->iPos);
  }

  public function fnLookahead() 
  {
    return $this->fnAt($this->fnNextIndex($this->iPos));
  }

  public function fnAdvance() 
  {
    $this->iPos = $this->fnNextIndex($this->iPos);
  }

  public function fnEat($iCh) 
  {
    if ($this->fnCurrent() === $iCh) {
      $this->fnAdvance();
      return true;
    }
    return false;
  }
}
