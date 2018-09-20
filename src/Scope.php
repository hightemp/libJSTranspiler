<?php

namespace libJSTranspiler;

class Scope
{
  const SCOPE_TOP = 1;
  const SCOPE_FUNCTION = 2;
  const SCOPE_VAR = self::SCOPE_TOP | self::SCOPE_FUNCTION;
  const SCOPE_ASYNC = 4;
  const SCOPE_GENERATOR = 8;
  const SCOPE_ARROW = 16;
  const SCOPE_SIMPLE_CATCH = 32;

  // Used in checkLVal and declareName to determine the type of a binding
  const BIND_NONE = 0; // Not a binding
  const BIND_VAR = 1; // Var-style binding
  const BIND_LEXICAL = 2; // Let- or const-style binding
  const BIND_FUNCTION = 3; // Function declaration
  const BIND_SIMPLE_CATCH = 4; // Simple (identifier pattern) catch binding
  const BIND_OUTSIDE = 5;
  
  public $iFlags;
  public $aVar;
  public $aLexical;
  
  public static function fnFunctionFlags($bAsync=false, $bGenerator=false) {
    return self::SCOPE_FUNCTION 
      | ($bAsync ? self::SCOPE_ASYNC : 0) 
      | ($bGenerator ? self::SCOPE_GENERATOR : 0);
  }

  public function __construct($iFlags) 
  {
    $this->iFlags = $iFlags;
    // A list of var-declared names in the current lexical scope
    $this->aVar = [];
    // A list of lexically-declared names in the current lexical scope
    $this->aLexical = [];
  }
  
}

