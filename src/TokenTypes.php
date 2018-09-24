<?php

namespace libJSTranspiler;

use libJSTranspiler\TokenType;
use libJSTranspiler\TokenContextTypes;

class TokenTypes
{
  public static $aTypes = [];
  public static $aKeywords = [
    "break" => null,
    "case" => null,
    "catch" => null,
    "continue" => null,
    "debugger" => null,
    "default" => null,
    "do" => null,
    "else" => null,
    "finally" => null,
    "for" => null,
    "function" => null,
    "if" => null,
    "return" => null,
    "switch" => null,
    "throw" => null,
    "try" => null,
    "var" => null,
    "const" => null,
    "while" => null,
    "with" => null,
    "new" => null,
    "this" => null,
    "super" => null,
    "class" => null,
    "extends" => null,
    "export" => null,
    "import" => null,
    "null" => null,
    "true" => null,
    "false" => null,
    "in" => null,
    "instanceof" => null,
    "typeof" => null,
    "void" => null,
    "delete" => null,      
  ];
  
  public static function fnAdd($sName, $sSymbol, $mConf=[])
  {
    self::$aTypes[$sName] = new TokenType($sSymbol, $mConf);
  }
  
  public static function fnAddArray($aArray)
  {
    foreach ($aArray as $aItem) {
      self::fnAdd($aItem[0], $aItem[1], isset($aItem[2]) ? $aItem[2] : []);
    }
  }
}

TokenTypes::fnAddArray([
  ["num", "num", ['startsExpr' => true]],
  ["regexp", "regexp", ['startsExpr' => true]],
  ["string", "string", ['startsExpr' => true]],
  ["name", "name", ['startsExpr' => true]],
  ["eof", "eof"],

  // Punctuation token types.
  ["bracketL", "[", ['beforeExpr' => true, 'startsExpr' => true]],
  ["bracketR", "]"],
  ["braceL", "{", ['beforeExpr' => true, 'startsExpr' => true]],
  ["braceR", "}"],
  ["parenL", "(", ['beforeExpr' => true, 'startsExpr' => true]],
  ["parenR", "]"],
  ["comma", ",", ['beforeExpr' => true]],
  ["semi", ";", ['beforeExpr' => true]],
  ["colon", ":", ['beforeExpr' => true]],
  ["dot", "."],
  ["question", "?", ['beforeExpr' => true]],
  ["arrow", "=>", ['beforeExpr' => true]],
  ["template", "template"],
  ["invalidTemplate", "invalidTemplate"],
  ["ellipsis", "...", ['beforeExpr' => true]],
  ["backQuote", "`", ['startsExpr' => true]],
  ["dollarBraceL", '${', ['beforeExpr' => true, 'startsExpr' => true]],

  // Operators. These carry several kinds of properties to help the
  // parser use them properly (the presence of these properties is
  // what categorizes them as operators).
  //
  // `binop`, when present, specifies that this operator is a binary
  // operator, and will refer to its precedence.
  //
  // `prefix` and `postfix` mark the operator as a prefix or postfix
  // unary operator.
  //
  // `isAssign` marks all of `=`, `+=`, `-=` etcetera, which act as
  // binary operators with a very low precedence, that should result
  // in AssignmentExpression nodes.

  ["eq", "=", ['beforeExpr' => true, 'isAssign' => true]],
  ["assign", "_=", ['beforeExpr' => true, 'isAssign' => true]],
  ["incDec", "++/--", ['prefix' => true, 'postfix' => true, 'startsExpr' => true]],
  ["prefix", "!/~", ['beforeExpr' => true, 'prefix' => true, 'startsExpr' => true]],
  ["logicalOR", "||", ['beforeExpr' => true, 'binop' => 1]],
  ["logicalAND", "&&", ['beforeExpr' => true, 'binop' => 2]],
  ["bitwiseOR", "|", ['beforeExpr' => true, 'binop' => 3]],
  ["bitwiseXOR", "^", ['beforeExpr' => true, 'binop' => 4]],
  ["bitwiseAND", "&", ['beforeExpr' => true, 'binop' => 5]],
  ["equality", "==/!=/===/!==", ['beforeExpr' => true, 'binop' => 6]],
  ["relational", "</>/<=/>=", ['beforeExpr' => true, 'binop' => 7]],
  ["bitShift", "<</>>/>>>", ['beforeExpr' => true, 'binop' => 8]],
  ["plusMin", "+/-", ['beforeExpr' => true, 'binop' => 9, 'prefix' => true, 'startsExpr' => true]],
  ["modulo", "%", ['beforeExpr' => true, 'binop' => 10]],
  ["star", "*", ['beforeExpr' => true, 'binop' => 10]],
  ["slash", "/", ['beforeExpr' => true, 'binop' => 10]],
  ["starstar", "**", ['beforeExpr' => true]],

  // Keyword token types.
  ["break", "break", ['keyword' => "break"]],
  ["case", "case", ['keyword' => "case", 'beforeExpr' => true]],
  ["catch", "catch", ['keyword' => "catch"]],
  ["continue", "continue", ['keyword' => "continue"]],
  ["debugger", "debugger", ['keyword' => "debugger"]],
  ["default", "default", ['keyword' => "default", 'beforeExpr' => true]],
  ["do", "do", ['keyword' => "do", 'isLoop' => true, 'beforeExpr' => true]],
  ["else", "else", ['keyword' => "else", 'beforeExpr' => true]],
  ["finally", "finally", ['keyword' => "finally"]],
  ["for", "for", ['keyword' => "for", 'isLoop' => true]],
  ["function", "function", ['keyword' => "function", 'startsExpr' => true]],
  ["if", "if", ['keyword' => "if"]],
  ["return", "return", ['keyword' => "return", 'beforeExpr' => true]],
  ["switch", "switch", ['keyword' => "switch"]],
  ["throw", "throw", ['keyword' => "throw", 'beforeExpr' => true]],
  ["try", "try", ['keyword' => "try"]],
  ["var", "var", ['keyword' => "var"]],
  ["const", "const", ['keyword' => "const"]],
  ["while", "while", ['keyword' => "while", 'isLoop' => true]],
  ["with", "with", ['keyword' => "with"]],
  ["new", "new", ['keyword' => "new", 'beforeExpr' => true, 'startsExpr' => true]],
  ["this", "this", ['keyword' => "this", 'startsExpr' => true]],
  ["super", "super", ['keyword' => "super", 'startsExpr' => true]],
  ["class", "class", ['keyword' => "class", 'startsExpr' => true]],
  ["extends", "extends", ['keyword' => "extends", 'beforeExpr' => true]],
  ["export", "export", ['keyword' => "export"]],
  ["import", "import", ['keyword' => "import"]],
  ["null", "null", ['keyword' => "null", 'startsExpr' => true]],
  ["true", "true", ['keyword' => "true", 'startsExpr' => true]],
  ["false", "false", ['keyword' => "false", 'startsExpr' => true]],
  ["in", "in", ['keyword' => "in", 'beforeExpr' => true, 'binop' => 7]],
  ["instanceof", "instanceof", ['keyword' => "instanceof", 'beforeExpr' => true, 'binop' => 7]],
  ["typeof", "typeof", ['keyword' => "typeof", 'beforeExpr' => true, 'prefix' => true, 'startsExpr' => true]],
  ["void", "void", ['keyword' => "void", 'beforeExpr' => true, 'prefix' => true, 'startsExpr' => true]],
  ["delete", "delete", ['keyword' => "delete", 'beforeExpr' => true, 'prefix' => true, 'startsExpr' => true]],
]);

foreach (TokenTypes::$aKeywords as $sKeyword => &$oReference) {
  $oReference = TokenTypes::$aTypes[$sKeyword];
}

TokenTypes::$aTypes['parenR']->fnUpdateContext = 
TokenTypes::$aTypes['braceR']->fnUpdateContext = 
function() 
{
  if (count($this->aContext) === 1) {
    $this->bExprAllowed = true;
    return;
  }
  $oOut = array_pop($this->aContext);
  if ($oOut === TokenContextTypes::$aTypes['b_stat'] 
      && $this->fnCurContext()->sToken === "function") {
    $oOut = array_pop($this->aContext);
  }
  $this->bExprAllowed = !$oOut->bIsExpr;
};

TokenTypes::$aTypes['braceL']->fnUpdateContext = function($oPrevType) 
{
  array_push(
    $this->aContext, 
    $this->fnBraceIsBlock($oPrevType) ? 
      TokenContextTypes::$aTypes['b_stat'] :
      TokenContextTypes::$aTypes['b_expr']
  );
  $this->bExprAllowed = true;
};

TokenTypes::$aTypes['dollarBraceL']->fnUpdateContext = function() 
{
  array_push(
    $this->aContext,
    TokenContextTypes::$aTypes['b_tmpl']
  );
  $this->bExprAllowed = true;
};

TokenTypes::$aTypes['parenL']->fnUpdateContext = function($oPrevType) 
{
  $bStatementParens = $oPrevType === TokenTypes::$aTypes['if']
    || $oPrevType === TokenTypes::$aTypes['for']
    || $oPrevType === TokenTypes::$aTypes['with']
    || $oPrevType === TokenTypes::$aTypes['while'];
  array_push(
    $this->aContext,
    $bStatementParens ?
    TokenContextTypes::$aTypes['p_stat'] :
    TokenContextTypes::$aTypes['p_expr']
  );
  $this->bExprAllowed = true;
};

TokenTypes::$aTypes['incDec']->fnUpdateContext = function() 
{
  // tokExprAllowed stays unchanged
};

TokenTypes::$aTypes['function']->fnUpdateContext = 
TokenTypes::$aTypes['class']->fnUpdateContext = 
function($oPrevType) 
{
  if ($oPrevType->bBeforeExpr 
      && $oPrevType !== TokenTypes::$aTypes['semi'] 
      && $oPrevType !== TokenTypes::$aTypes['else']
      &&
        !(($oPrevType === TokenTypes::$aTypes['colon'] 
            || $oPrevType === TokenTypes::$aTypes['braceL']) 
          && $this->fnCurContext() === TokenContextTypes::$aTypes['b_stat']))
    array_push(
      $this->aContext,
      TokenContextTypes::$aTypes['f_expr']
    );
  else
    array_push(
      $this->aContext,
      TokenContextTypes::$aTypes['f_stat']
    );
  $this->bExprAllowed = false;
};

TokenTypes::$aTypes['backQuote']->fnUpdateContext = function() 
{
  if ($this->fnCurContext() === TokenContextTypes::$aTypes['q_tmpl'])
    array_pop($this->aContext);
  else
    array_push(
      $this->aContext,
      TokenContextTypes::$aTypes['q_tmpl']
    );
  $this->bExprAllowed = false;
};

TokenTypes::$aTypes['star']->fnUpdateContext = function($oPrevType) 
{
  if ($oPrevType === TokenTypes::$aTypes['function']) {
    $iIndex = count($this->aContext) - 1;
    if ($this->aContext[$iIndex] === TokenContextTypes::$aTypes['f_expr'])
      $this->aContext[$iIndex] = TokenContextTypes::$aTypes['f_expr_gen'];
    else
      $this->aContext[$iIndex] = TokenContextTypes::$aTypes['f_gen'];
  }
  $this->bExprAllowed = true;
};

TokenTypes::$aTypes['name']->fnUpdateContext = function($oPrevType) 
{
  $bAllowed = false;
  if ($this->aOptions['ecmaVersion'] >= 6 
      && $oPrevType !== TokenTypes::$aTypes['dot']) {
    if ($this->mValue === "of" && !$this->bExprAllowed ||
        $this->mValue === "yield" && $this->fnInGeneratorContext())
      $bAllowed = true;
  }
  $this->bExprAllowed = $bAllowed;
};

