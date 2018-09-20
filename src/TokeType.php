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