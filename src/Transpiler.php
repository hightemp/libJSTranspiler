<?php

namespace libJSTranspiler;

use libJSTranspiler\Parser;
use libJSTranspiler\Utilities;

class Transpiler
{
  const BOOL_SAFE_OPS = [
    "===", "!==",
    "==", "!=",
    "<", "<=",
    ">", ">=",
    "in",
    "instanceof"
  ];
  
  const PRECEDENCE = [
    "SEQUENCE" => 0,
    "YIELD" => 1,
    "ASSIGNMENT" => 1,
    "CONDITIONAL" => 2,
    "ARROW_FUNCTION" => 2,
    "LOGICAL_OR" => 3,
    "LOGICAL_AND" => 4,
    "BITWISE_OR" => 5,
    "BITWISE_XOR" => 6,
    "BITWISE_AND" => 7,
    "EQUALITY" => 8,
    "RELATIONAL" => 9,
    "BITWISE_SHIFT" => 10,
    "ADDITIVE" => 11,
    "MULTIPLICATIVE" => 12,
    "UNARY" => 13,
    "POSTFIX" => 14,
    "CALL" => 15,
    "NEW" => 16,
    "TAGGED_TEMPLATE" => 17,
    "MEMBER" => 18,
    "PRIMARY" => 19      
  ];
  
  const BINARY_PRECEDENCE = [
    "||" => self::PRECEDENCE["LOGICAL_OR"],
    "&&" => self::PRECEDENCE["LOGICAL_AND"],
    "|" => self::PRECEDENCE["BITWISE_OR"],
    "^" => self::PRECEDENCE["BITWISE_XOR"],
    "&" => self::PRECEDENCE["BITWISE_AND"],
    "==" => self::PRECEDENCE["EQUALITY"],
    "!=" => self::PRECEDENCE["EQUALITY"],
    "===" => self::PRECEDENCE["EQUALITY"],
    "!==" => self::PRECEDENCE["EQUALITY"],
    "<" => self::PRECEDENCE["RELATIONAL"],
    ">" => self::PRECEDENCE["RELATIONAL"],
    "<=" => self::PRECEDENCE["RELATIONAL"],
    ">=" => self::PRECEDENCE["RELATIONAL"],
    "in" => self::PRECEDENCE["RELATIONAL"],
    "instanceof" => self::PRECEDENCE["RELATIONAL"],
    "<<" => self::PRECEDENCE["BITWISE_SHIFT"],
    ">>" => self::PRECEDENCE["BITWISE_SHIFT"],
    ">>>" => self::PRECEDENCE["BITWISE_SHIFT"],
    "+" => self::PRECEDENCE["ADDITIVE"],
    "-" => self::PRECEDENCE["ADDITIVE"],
    "*" => self::PRECEDENCE["MULTIPLICATIVE"],
    "%" => self::PRECEDENCE["MULTIPLICATIVE"],
    "/" =>self::PRECEDENCE["MULTIPLICATIVE"]
  ];

  //these operators expect numbers
  const UNARY_NUM_OPS = [
    "-",
    "+",
    "~" //bitwise not
  ];

  //these operators expect numbers
  const BINARY_NUM_OPS = [
    // `+` is not in this list because it"s a special case
    "-",
    "%",
    "*",
    "/",
    // let"s assume these comparison operators are only used on numbers
    //"<", "<=",
    //">", ">=",
    "&", //bitwise and
    "|", //bitwise or
    "^", //bitwise xor
    "<<", //bitwise left shift
    ">>", //bitwise sign-propagating right shift
    ">>>" //bitwise zero-fill right shift
  ];
  
  const NUM_SAFE_UNARY_OPS = [
    "-",
    "+",
    "~"
  ];
  
  const NUM_SAFE_BINARY_OPS = [
    // `+` is not in this list because it"s a special case
    "-",
    "%",
    "*", "/",
    "&", "|",
    "^",
    "<<", ">>",
    ">>>"
  ];
  
  const GLOBALS = [
    "Array", 
    "Boolean", 
    "Buffer", 
    "Date", 
    "Error", 
    "RangeError", 
    "ReferenceError", 
    "SyntaxError", 
    "TypeError", 
    "Function", 
    "Infinity", 
    "JSON", 
    "Math", 
    "NaN", 
    "Number", 
    "Object", 
    "RegExp", 
    "String", 
    "console", 
    "decodeURI", 
    "decodeURIComponent", 
    "encodeURI", 
    "encodeURIComponent", 
    "escape", 
    "eval", 
    "isFinite", 
    "isNaN", 
    "parseFloat", 
    "parseInt", 
    "undefined", 
    "unescape"
  ];

  const SCOPE_TYPES = [
    "FunctionDeclaration", 
    "FunctionExpression", 
    "Program"
  ];

  //these have special meaning in PHP so we escape variables with these names
  const SUPER_GLOBALS = [
    "GLOBALS", 
    "_SERVER", 
    "_GET", 
    "_POST", 
    "_FILES", 
    "_COOKIE", 
    "_SESSION", 
    "_REQUEST", 
    "_ENV"
  ];
  
  public $aOpts;
  
  public function __construct($aOpts=[])
  {
    $aDefaultOpts = [
      "indentLevel" => 0,
    ];
    $this->aOpts = array_merge($aDefaultOpts, $aOpts);
  }

  public function toBlock($oNode) 
  {
    $aOpts = &$this->aOpts;
    if ($oNode->sType === "BlockStatement") {
      return $this->fnBody($oNode);
    }
    
    $aOpts["indentLevel"] += 1;
    
    $sResult = $this->fnGenerate($oNode);
    if ($sResult) {
      $sResult = $this->fnIndent() . $sResult;
    }
    
    $aOpts["indentLevel"] -= 1;
    
    return $sResult;
  }
  
  public function fnBody($oNode) 
  {
    $aOpts = &$this->aOpts;
    $oScopeNode = ($oNode->sType === "BlockStatement") ? $oNode->rParent : $oNode;
    $aScopeIndex = $oScopeNode->aScopeIndex;
    $aResults = [];
    
    $aOpts["indentLevel"] += 1;
    
    if ($aScopeIndex["thisFound"]) {
      if ($oNode->sType === "Program") {
        array_push($aResults, $this->fnIndent() . "\$this_ = \$global;\n");
      } else {
        array_push($aResults, $this->fnIndent() . "\$this_ = Func::getContext();\n");
      }
    }
    if ($aScopeIndex["argumentsFound"] && $oNode->sType !== "Program") {
      array_push($aResults, $this->fnIndent() . "\$arguments = Func::getArguments();\n");
    }
    if ($oNode->aVars && $aOpts["initVars"]) {
      $aDeclarations = [];
      foreach($oNode->aVars as $sName => $sValue) {
        array_push($aDeclarations, $this->fnEncodeVarName($sName) . " = null;");
      };
      if (declarations.length) {
        array_push($aResults, $this->fnIndent() . join(" ", $aDeclarations) . "\n");
      }
    }
    $aFuncDeclarations = $oNode->aFuncs;
    if ($aFuncDeclarations) {
      foreach($aFuncDeclarations as $sName => $sValue) {
        $sFunc = $this->fnFunctionExpression($aFuncDeclarations[$sName]);
        array_push($aResults, $this->fnIndent() . $this->fnEncodeVarName($sName) . " = " . $sFunc . ";\n");
      };
    }

    foreach($oNode->aBody as $oNode) {
      $sResult = $this->fnGenerate($oNode, $oParent);
      if ($sResult) {
        array_push($aResults, $this->fnIndent() . $sResult);
      }
    }
    
    if ($aOpts["indentLevel"] > 0) {
      $aOpts["indentLevel"] -= 1;
    }
    return join("", $aResults);
  }

  public function fnBlockStatement($oNode) 
  {
    $aResults = ["{\n"];
    array_push($aResults, $this->fnBody($oNode));
    array_push($aResults, $this->fnIndent() . "}");
    return join("", $aResults) . "\n";
  }

  public function fnVariableDeclaration($oNode) 
  {
    $aResults = [];
    foreach ($oNode->aDeclarations as $oDNode) {
      if ($oDNode->oInit) {
        array_push($aResults, $this->fnEncodeVar($oDNode->oId) . " = " . $this->fnGenerate($oDNode->oInit));
      }
    }
    if (!count($aResults)) {
      return "";
    }
    if ($oNode->rParent->sType === "ForStatement") { //?
      return join(", ", $aResults);
    }
    return join("; ", $aResults) . ";\n";
  }

  public function fnIfStatement($oNode) 
  {
    $aResults = ["if ("];
    array_push($aResults, $this->fnTruthyWrap($oNode->oTest));
    array_push($aResults, ") {\n");
    array_push($aResults, $this->fnToBlock($oNode->oConsequent));
    array_push($aResults, $this->fnIndent() . "}");
    if ($oNode->oAlternate) {
      array_push($aResults, " else ");
      if ($oNode->oAlternate->sType === "IfStatement") {
        array_push($aResults, $this->fnGenerate($oNode->oAlternate));
      } else {
        array_push($aResults, "{\n");
        array_push($aResults, $this->fnToBlock($oNode->oAlternate));
        array_push($aResults, $this->fnIndent() . "}\n");
      }
    }
    return join("", $aResults) . "\n";
  }

  public function fnSwitchStatement($oNode) 
  {
    $aOpts = &$this->aOpts;
    $aResults = ["switch ("];
    array_push($aResults, $this->fnGenerate($oNode->oDiscriminant));
    array_push($aResults, ") {\n");
    
    $aOpts["indentLevel"] += 1;
    
    foreach ($oNode->aCases as $oCNode) {
      array_push($aResults, $this->fnIndent());
      if ($oCNode->oTest === null) {
        array_push($aResults, "default:\n");
      } else {
        array_push($aResults, "case " . $this->fnGenerate($oCNode->oTest) . ":\n");
      }
      
      $aOpts["indentLevel"] += 1;
      
      foreach ($oNode->oConsequent as $oConsequentNode) {
        array_push($aResults, $this->fnIndent() . $this->fnGenerate($oConsequentNode));
      }
      
      $aOpts["indentLevel"] -= 1;
    }
    
    $aOpts["indentLevel"] -= 1;
    
    array_push($aResults, $this->fnIndent() . "}");
    return join("", $aResults) . "\n";
  }

  public function fnConditionalExpression($oNode) 
  {
    //PHP has "non-obvious" ternary operator precedence according to the docs
    // these are safe: Literal, Identifier, ThisExpression,
    // FunctionExpression, CallExpression, MemberExpression, NewExpression,
    // ArrayExpression, ObjectExpression, SequenceExpression, UnaryExpression
    $sAlternate = $this->fnGenerate($oNode->oAlternate);
    switch ($oNode->oAlternate->sType) {
      case "AssignmentExpression":
      case "BinaryExpression":
      case "LogicalExpression":
      case "UpdateExpression":
      case "ConditionalExpression":
        $sAlternate = "(" . $sAlternate . ")";
        break;
    }
    return $this->fnTruthyWrap($oNode->oTest) . " ? " . $this->fnGenerate($oNode->oConsequent) . " : " . $sAlternate;
  }

  public function fnForStatement($oNode) 
  {
    $aResults = ["for ("];
    array_push($aResults, $this->fnGenerate($oNode->oInit) . "; ");
    array_push($aResults, $this->fnTruthyWrap($oNode->oTest) . "; ");
    array_push($aResults, $this->fnGenerate($oNode->oUpdate));
    array_push($aResults, ") {\n");
    array_push($aResults, $this->fnToBlock($oNode->aBody));
    array_push($aResults, $this->fnIndent() . "}");
    return join("", $aResults) . "\n";
  }

  public function fnForInStatement($oNode) 
  {
    $aResults = [];
    $oIdentifier;
    if ($oNode->oLeft->sType === "VariableDeclaration") {
      $oIdentifier = $oNode->oLeft->aDeclarations[0]->oId;
    } else
    if ($oNode->oLeft->sType === "Identifier") {
      $oIdentifier = $oNode->oLeft;
    } else {
      throw new Exception("Unknown left part of for..in `" . $oNode->oLeft->sType . "`");
    }
    array_push($aResults, "foreach (keys(");
    array_push($aResults, $this->fnGenerate($oNode->oRight) . ") as " . $this->fnEncodeVar($oIdentifier) . ") {\n");
    array_push($aResults, $this->fnToBlock($oNode->aBody));
    array_push($aResults, $this->fnIndent() . "}");
    return join("", $aResults) . "\n";
  }

  public function fnWhileStatement($oNode) 
  {
    $aResults = ["while ("];
    array_push($aResults, $this->fnTruthyWrap($oNode->oTest));
    array_push($aResults, ") {\n");
    array_push($aResults, $this->fnToBlock($oNode->aBody));
    array_push($aResults, $this->fnIndent() . "}");
    return join("", $aResults) . "\n";
  }

  public function fnDoWhileStatement($oNode) 
  {
    $aResults = ["do {\n"];
    array_push($aResults, $this->fnToBlock($oNode->aBody));
    array_push($aResults, $this->fnIndent() . "} while (" . $this->fnTruthyWrap($oNode->oTest) . ");");
    return join("", $aResults) . "\n";
  }

  public function fnTryStatement($oNode) 
  {
    $oCatchClause = $oNode->aHandlers[0]; //?
    $oParam = $oCatchClause->oParam;
    $aResults = ["try {\n"];
    array_push($aResults, $this->fnBody($oNode->oBlock));
    array_push($aResults, $this->fnIndent() . "} catch(Exception " . $this->fnEncodeVar($oParam) . ") {\n");
    array_push($aResults, $this->fnIndent(1) . "if (" . $this->fnEncodeVar($oParam) . " instanceof Ex) " . $this->fnEncodeVar($oParam) . " = " . $this->fnEncodeVar($oParam) . "->value;\n");
    array_push($aResults, $this->fnBody($oCatchClause->aBody));
    array_push($aResults, $this->fnIndent() . "}");
    return join("", $aResults) . "\n";
  }

  public function fnThrowStatement($oNode) 
  {
    return "throw new Ex(" . $this->fnGenerate($oNode->oArgument) . ");\n";
  }

  public function fnFunctionExpression($oNode) 
  {
    $aMeta = [];
    $aOpts = &$this->aOpts;
    $bParentIsStrict = $aOpts["isStrict"];
    $aOpts["isStrict"] = $bParentIsStrict || $this->fnIsStrictDirective($oNode->aBody->aBody[0]);
    if ($oNode->bUseStrict === false) { //?
      $aOpts["isStrict"] = false;
    }
    if ($aOpts["isStrict"]) {
      array_push($aMeta, "\"strict\" => true");
    }
    $aResults = ["new Func("];
    if ($oNode->oId) {
      array_push($aResults, $this->fnEncodeString($oNode->oId->sName) . ", ");
    }
    $aParams = [];
    foreach ($oNode->aParams as $sParam) {
      $aParams[] = $this->fnEncodeVar($sParam) . " = null";
    }
    $aScopeIndex = $oNode->aScopeIndex; //?
    $sFunctionName = $oNode->oId ? $oNode->oId->sName : "";
    if ($aScopeIndex["unresolved"][$sFunctionName]) {
      unset($aScopeIndex["unresolved"][$sFunctionName]);
    }
    $aUnresolvedRefs = [];
    foreach (array_keys($aScopeIndex["unresolved"]) as $sName) {
      $aUnresolvedRefs[] = $this->fnEncodeVarName(name);
    }
    $sUseClause = count($aUnresolvedRefs) ? "use (&" . join(", &", $aUnresolvedRefs) . ") " : "";
    array_push($aResults, "function(" . join(", ", $aParams) . ") " . $sUseClause . "{\n");
    if ($aScopeIndex["referenced"][$sFunctionName]) {
      array_push($aResults, $this->fnIndent(1) . $this->fnEncodeVarName($sFunctionName) . " = Func::getCurrent();\n");
    }
    array_push($aResults, $this->fnBody($oNode->aBody));
    array_push($aResults, $this->fnIndent() . "}");
    if (count($aMeta)) {
      array_push($aResults, ", array(" . join(", ", $aMeta) . ")");
    }
    array_push($aResults, ")");
    $aOpts["isStrict"] = $bParentIsStrict;
    return join("", $aResults);
  }

  public function fnArrayExpression($oNode) 
  {
    $aItems = [];
    foreach ($oNode->aElements as $oEl) {
      $aItems[] = ($oEl === null) ? "Arr::\$empty" : $this->fnGenerate($oEl);
    }
    return "new Arr(" . join(", ", $aItems) . ")";
  }

  public function fnObjectExpression($oNode) 
  {
    $aItems = [];
    foreach ($oNode->aProperties as $oPNode) {
      $oKey = $oPNode->oKey;
      //key can be a literal or an identifier (quoted or not)
      $sKeyName = ($oKey->sType === "Identifier") ? $oKey->sName : $oKey->mValue;
      array_push($aItems, $this->fnEncodeString($sKeyName));
      array_push($aItems, $this->fnGenerate($oNode->mValue));
    }
    return "new Object(" . join(", ", $aItems) . ")";
  }

  public function fnCallExpression($oNode) 
  {
    $aArgs = [];
    foreach ($oNode->aArguments as $oArg) {
      $aArgs[] = $this->fnGenerate($oArg);
    }
    if ($oNode->oCallee->sType === "MemberExpression") {
      return "call_method(" . 
        $this->fnGenerate($oNode->oCallee->oObject) . ", " . 
        $this->fnEncodeProp($oNode->oCallee) . 
        (count($aArgs) ? ", " . join(", ", $aArgs) : "") . ")";
    } else {
      return "call(" . $this->fnGenerate($oNode->oCallee) . 
        (count($aArgs) ? ", " . join(", ", $aArgs) : "") . ")";
    }
  }

  public function fnMemberExpression($oNode) 
  {
    return "get(" . $this->fnGenerate($oNode->oObject) . ", " . $this->fnEncodeProp($oNode) . ")";
  }

  public function fnNewExpression($oNode) 
  {
    $aArgs = [];
    foreach ($oNode->aArguments as $oArg) {
      $aArgs[] = $this->fnGenerate($oArg);
    }
    return "_new(" . $this->fnGenerate($oNode->oCallee) . (count($aArgs) ? ", " . join(", ", $aArgs) : "") . ")";
  }

  public function fnAssignmentExpression($oNode) 
  {
    if ($oNode->oLeft->sType === "MemberExpression") {
      //`a.b = 1` -> `set(a, "b", 1)` but `a.b += 1` -> `set(a, "b", 1, "+=")`
      if ($oNode.sOperator === "=") {
        return "set(" . $this->fnGenerate($oNode->oLeft->oObject) . ", " . $this->fnEncodeProp($oNode->oLeft) . ", " . $this->fnGenerate($oNode->oRight) . ")";
      } else {
        return "set(" . $this->fnGenerate($oNode->oLeft->oObject) . ", " . $this->fnEncodeProp($oNode->oLeft) . ", " . $this->fnGenerate($oNode->oRight) . ", \"" . $oNode->sOperator . "\")";
      }
    }
    if (in_array($oNode->oLeft->sName, self::GLOBALS)) {
      $oScope = $this->fnGetParentScope($oNode); //?
      if ($oScope->sType === "Program") {
        $oNode->oLeft->sAppendSuffix = "_";
      }
    }
    //special case += since plus can be either add or concatenate
    if ($oNode->sOperator === "+=") {
      $sIdent = $this->fnGenerate($oNode->oLeft);
      return $sIdent . " = _plus(" . $sIdent . ", " . $this->fnGenerate($oNode->oRight) . ")";
    }
    return $this->fnEncodeVar($oNode->oLeft) . " " . $oNode.sOperator . " " . $this->fnGenerate($oNode->oRight);
  }

  public function fnUpdateExpression($oNode) 
  {
    if ($oNode->oArgument->sType === "MemberExpression") {
      //convert `++a` to `a += 1`
      $sOperator = ($oNode->sOperator === "++") ? "+=" : "-=";
      // ++i returns the new (updated) value; i++ returns the old value
      $bReturnOld = $oNode->bPrefix ? false : true;
      return "set(" . $this->fnGenerate($oNode->oArgument->oObject) . ", " . 
             $this->fnEncodeProp($oNode->oArgument) . ", 1, \"" . 
             $sOperator . "\", " . $bReturnOld . ")";
    }
    //special case (i++ and ++i) assume type is number
    if ($oNode->bPrefix) {
      return $oNode->sOperator . $this->fnGenerate($oNode->oArgument);
    } else {
      return $this->fnGenerate($oNode->oArgument) . $oNode->sOperator;
    }
  }

  public function fnLogicalExpression($oNode) 
  {
    $sOp = $oNode->sOperator;
    if ($this->fnIsBooleanExpr($oNode)) {
      $sResult = $this->fnGenerate($oNode->oLeft) . " " . $sOp . " " . $this->fnGenerate($oNode->oRight);
      if ($this->fnOpPrecedence($sOp) < $this->fnOpPrecedence($oNode->rParent->sOperator)) {
        $sResult = "(" . result . ")";
      }
      return $sResult;
    }
    if ($sOp === "&&") {
      return $this->fnGenAnd($oNode);
    }
    if ($sOp === "||") {
      return $this->fnGenOr($oNode);
    }
  }

  public function fnGenAnd($oNode) 
  {
    $aOpts = &$this->aOpts;
    $aOpts["andDepth"] = ($aOpts["andDepth"] == null) ? 0 : $aOpts["andDepth"] + 1;
    $sName = ($aOpts["andDepth"] === 0) ? "\$and_" : "\$and" . $aOpts["andDepth"] . "_";
    $sTest = "(" . $sName . " = " . $this->fnGenerate($oNode->oLeft) . ")";
    if (!$this->fnIsBooleanExpr($oNode->oLeft)) {
      $sTest = "is" . $sTest;
    }
    $sResult = "(" . $sTest . " ? " . $this->fnGenerate($oNode->oRight) . " : " . $sName . ")";
    $aOpts["andDepth"] = ($aOpts["andDepth"] === 0) ? null : $aOpts["andDepth"] - 1;
    return $sResult;
  }

  public function fnGenOr($oNode) 
  {
    $aOpts = &$this->aOpts;
    $aOpts["orDepth"] = ($aOpts["orDepth"] == null) ? 0 : $aOpts["orDepth"] + 1;
    $sName = ($aOpts["orDepth"] === 0) ? "\$or_" : "\$or" + $aOpts["orDepth"] + "_";
    $sTest = "(" . $sName . " = " . $this->fnGenerate($oNode->oLeft) . ")";
    if (!$this->fnIsBooleanExpr($oNode->oLeft)) {
      $sTest = "is" . $sTest;
    }
    $sResult = "(" . $sTest . " ? " . $sName . " : " . $this->fnGenerate($oNode->oRight) . ")";
    $aOpts["orDepth"] = ($aOpts["orDepth"] === 0) ? null : $aOpts["orDepth"] - 1;
    return $sResult;
  }

  public function fnBinaryExpression($oNode) 
  {
    $sOp = $oNode->sOperator;
    if ($sOp === "+") {
      $aTerms = [];
      foreach ($oNode->aTerms as $oValue) {
        $aTerms[] = $this->fnGenerate($oValue);
      }
      if ($oNode->bIsConcat) {
        return "_concat(" . join(", ", $aTerms) . ")";
      } else {
        return "_plus(" . join(", ", $aTerms) . ")";
      }
    }
    if ($sOp === "==") {
      return "eq(" . $this->fnGenerate($oNode->oLeft) . ", " . $this->fnGenerate($oNode->oRight) . ")";
    }
    if ($sOp === "!=") {
      return "!eq(" . $this->fnGenerate($oNode->oLeft) . ", " . $this->fnGenerate($oNode->oRight) . ")";
    }
    
    $bCastFloat = false;
    // some ops will return int in which case we need to cast result
    if ($sOp === "%") {
      $bCastFloat = true;
    }
    $bToNumber = false;
    if (in_array($sOp, self::BINARY_NUM_OPS)) {
      $sOp = self::BINARY_NUM_OPS[$sOp];
      $bToNumber = true;
    } else
      if ($this->fnIsWord($sOp)) {
        //in, instanceof
        $sOp = "_" . $sOp;
      }
    $sLeftExpr = $this->fnGenerate($oNode->oLeft);
    $sRightExpr = $this->fnGenerate($oNode->oRight);
    if ($this->fnIsWord($sOp)) {
      return $sOp . "(" . $sLeftExpr . ", " . $sRightExpr . ")";
    } else
      if ($bToNumber) {
        if (!$this->fnIsNumericExpr($oNode->oLeft)) {
          $sLeftExpr = "to_number(" . $sLeftExpr . ")";
        }
        if (!$this->fnIsNumericExpr($oNode->oRight)) {
          $sRightExpr = "to_number(" . $sRightExpr . ")";
        }
      }
    $sResult = $sLeftExpr . " " . $sOp . " " . $sRightExpr;
    if ($bCastFloat) {
      $sResult = "(float)(" . $sResult . ")";
    } else
    if ($this->fnOpPrecedence($oNode->sOperator) < opPrecedence($oNode->rParent->sOperator)) {
      return "(" . result . ")";
    }
    return $sResult;
  }

  public function fnUnaryExpression($oNode) 
  {
    $sOp = $oNode->sOperator;
    if ($sOp === "!") {
      return $this->fnIsBooleanExpr($oNode->oArgument) ? "!" . $this->fnGenerate($oNode->oArgument) : "not(" . $this->fnGenerate($oNode->oArgument) . ")";
    }
    //special case here: -3 is just a number literal, not negate(3)
    if ($sOp === "-" && $oNode->oArgument->sType === "Literal" && is_numeric($oNode->oArgument->mValue)) {
      return "-" . $this->fnEncodeLiteral($oNode->oArgument->mValue);
    }
    //special case here: `typeof a` can be called on a non-declared variable
    if ($sOp === "typeof" && $oNode->oArgument->sType === "Identifier") {
      //isset($a) ? _typeof($a) : "undefined"
      return "(isset(" . $this->fnGenerate($oNode->oArgument) . ") ? _typeof(" . $this->fnGenerate($oNode->oArgument) . ") : \"undefined\")";
    }
    //special case here: `delete a.b.c` needs to compute a.b and then delete c
    if ($sOp === "delete" && $oNode->oArgument->sType === "MemberExpression") {
      return "_delete(" . $this->fnGenerate($oNode->oArgument->oObject) . ", " . $this->fnEncodeProp($oNode->oArgument) . ")";
    }
    $bToNumber = false;
    if (in_array($sOp, self::UNARY_NUM_OPS)) {
      $sOp = self::UNARY_NUM_OPS[$sOp];
      $bToNumber = true;
    } else
      if ($this->fnIsWord($sOp)) {
        //delete, typeof, void
        $sOp = "_" . $sOp;
      }
    $sResult = $this->fnGenerate($oNode->oArgument);
    if ($this->fnIsWord($sOp)) {
      $sResult = "(" . $sResult . ")";
    } else
    if ($bToNumber) {
      if ($oNode->oArgument->sType !== "Literal" || !is_numeric($oNode->oArgument->mValue)) {
        $sResult = "to_number(" . $sResult . ")";
      }
    }
    return $sOp . $sResult;
  }

  public function fnSequenceExpression($oNode) 
  {
    $aExpressions = [];
    foreach ($oNode->aExpressions as $oENode) {
      $aExpressions[] = $this->fnGenerate($oENode);
    }
    //allow sequence expression only in the init of a for loop
    if ($oNode->rParent->sType === "ForStatement" && $oNode->rParent->oInit == $oNode) {
      return join(", ", $aExpressions);
    } else {
      return "_seq(" . join(", ", $aExpressions) . ")";
    }
  }

  // used from if/for/while and ternary to determine truthiness
  public function fnTruthyWrap($oNode) 
  {
    //node can be null, for instance: `for (;;) {}`
    if (!$oNode) return "";
    $sOp = $oNode->sOperator;
    $sType = $oNode->sType;
    if ($sType === "LogicalExpression") {
      if ($sOp === "&&" || $sOp === "||") {
        $sResult = $this->fnTruthyWrap($oNode->oLeft) . " " . $sOp . " " . $this->fnTruthyWrap($oNode->oRight);
        if ($this->fnOpPrecedence($sOp) < $this->fnOpPrecedence($oNode->rParent->sOperator)) {
          $sResult = "(" . $sResult . ")";
        }
        return $sResult;
      }
    }
    if ($this->fnIsBooleanExpr($oNode)) {
      //prevent is($a === $b) and is(_in($a, $b)) and is(not($a))
      return $this->fnGenerate($oNode);
    } else {
      return "is(" . $this->fnGenerate($oNode) . ")";
    }
  }
  
  public function fnIsStrictDirective($oStmt)
  {
    if ($oStmt && $oStmt->sType === "ExpressionStatement") {
      $oExpr = $oStmt->oExpression;
      if ($oExpr->sType === "Literal" && $oExpr->mValue === "use strict") {
        return true;
      }
    }
    return false;
  }
  
  public function fnIsBooleanExpr($oNode) 
  {
    if ($oNode->sType === "LogicalExpression") {
      //prevent unnecessary (is($and_ = $a === $b) ? $c === $d : $and_)
      return $this->fnIsBooleanExpr($oNode->oLeft) && $this->fnIsBooleanExpr($oNode->oRight);
    }
    if ($oNode->sType === "Literal" && is_bool($oNode->mValue)) {
      //prevent is(true)
      return true;
    }
    if ($oNode->sType === "BinaryExpression" && in_array($oNode->sOperator, self::BOOL_SAFE_OPS)) {
      //prevent is($a === $b) and is(_in($a, $b))
      return true;
    }
    if ($oNode->sType === "UnaryExpression" && $oNode->sOperator === "!") {
      //prevent is(not($thing))
      return true;
    }
    return false;
  }

  // used to determine when we can omit to_number() so as to prevent stuff
  //  like: to_number(5.0 - 2.0) - 1.0;
  public function fnIsNumericExpr($oNode) 
  {
    if ($oNode->sType === "Literal" && is_numeric($oNode->mValue)) {
      return true;
    }
    if ($oNode->sType === "UnaryExpression" && in_array($oNode->sOperator, self::NUM_SAFE_UNARY_OPS)) {
      return true;
    }
    if ($oNode->sType === "BinaryExpression" && in_array($oNode->sOperator, self::NUM_SAFE_BINARY_OPS)) {
      return true;
    }
  }

  public function fnEncodeLiteral($mValue) 
  {
    $sType = gettype($mValue);
    
    if (!isset($mValue)) {
      return "null";
    }
    if ($sType === "NULL") {
      return "Object::\$null";
    }
    if ($sType === "string") {
      return $this->fnEncodeString($mValue);
    }
    if ($sType === "boolean") {
      return $mValue ? "true" : "false";
    }
    if ($sType === "integer" || $sType === "double") {
      return $mValue;
    }
    
    throw new Exception("No handler for literal of type: " . $sType . ": " . $mValue);
  }

  public function fnEncodeRegExp($mValue) 
  {
    /*
    $sFlags = "";
    if (value.global) flags += "g";
    if (value.ignoreCase) flags += "i";
    if (value.multiline) flags += "m";
    //normalize source to ensure no forward slashes are "escaped"
    var source = value.source.replace(/\\./g, function(s) {
      return (s === "\\/") ? "/" : s;
    });
    return "new RegExp(" + encodeString(source) + ", " + encodeString(flags) + ")";
     */
  }

  public function fnEncodeString($mValue) 
  {
    return Utilities::fnEncodeString($mValue);
  }

  public function fnEncodeVar($mIdentifier) 
  {
    /*
    var name = identifier.name;
    return $this->fnEncodeVarName(name, identifier.appendSuffix);
     */
  }

  public function fnEncodeVarName($sName, $sSuffix) 
  {
    return Utilities::fnEncodeVarName($sName, $sSuffix);
  }

  public function fnIsWord($sStr) 
  {
    return preg_match("/^\w+$/", $sStr);
  }

  public function fnOpPrecedence($sOp) 
  {
    return self::BINARY_PRECEDENCE[$sOp];
  }

  public function fnGetParentScope($oNode) 
  {
    $oParent = $oNode->rParent;
    while (!in_array($oParent->sType, self::SCOPE_TYPES)) {
      $oParent = $oParent->rParent;
    }
    return ($oParent->sType === "Program") ? $oParent : $oParent->aBody;
  }
  
  public function fnGenerate(&$oNode)
  {
    $aOpts = &$this->aOpts;
    if (!isset($aOpts["indentLevel"]) || !$aOpts["indentLevel"]) {
      $aOpts["indentLevel"] = -1;
    }
    //we might get a null call `for (; i < l; i++) { ... }`
    if ($oNode === null) {
      return "";
    }
    $sType = $oNode->sType;
    $sResult;
    switch ($sType) {
      //STATEMENTS
      case "Program":
        $aOpts["isStrict"] = $this->fnIsStrictDirective($oNode->aBody[0]);
        $sResult = $this->fnBody($oNode);
        break;
      case "ExpressionStatement":
        $sResult = $this->fnIsStrictDirective($oNode) ? "" : $this->fnGenerate($oNode->oExpression) . ";\n";
        break;
      case "ReturnStatement":
        $sResult = "return " . $this->fnGenerate($oNode->oArgument) . ";\n";
        break;
      case "ContinueStatement":
        $sResult = "continue;\n";
        break;
      case "BreakStatement":
        $sResult = "break;\n";
        break;
      case "EmptyStatement":
      case "DebuggerStatement":
      //this is handled at beginning of parent scope
      case "FunctionDeclaration":
        $sResult = "";
        break;
      case "VariableDeclaration":
      case "IfStatement":
      case "SwitchStatement":
      case "ForStatement":
      case "ForInStatement":
      case "WhileStatement":
      case "DoWhileStatement":
      case "BlockStatement":
      case "TryStatement":
      case "ThrowStatement":
        $sResult = $this->{"fn".$sType}($oNode);
        break;
      //these should never be encountered here because they are handled elsewhere
      case "SwitchCase":
      case "CatchClause":
        throw new Exception("should never encounter: \"" . $sType . "\"");
        break;
      //these are not implemented (some are es6, some are irrelevant)
      case "DirectiveStatement":
      case "ForOfStatement":
      case "LabeledStatement":
      case "WithStatement":
        throw new Exception("unsupported: \"" . $sType . "\"");
        break;

      //EXPRESSIONS
      case "Literal":
        $sResult = $this->fnEncodeLiteral($oNode->mValue);
        break;
      case "Identifier":
        $sResult = $this->fnEncodeVar($oNode);
        break;
      case "ThisExpression":
        $sResult = "$this_";
        break;
      case "FunctionExpression":
      case "AssignmentExpression":
      case "CallExpression":
      case "MemberExpression":
      case "NewExpression":
      case "ArrayExpression":
      case "ObjectExpression":
      case "UnaryExpression":
      case "BinaryExpression":
      case "LogicalExpression":
      case "SequenceExpression":
      case "UpdateExpression":
      case "ConditionalExpression":
        $sResult = $this->{"fn".$sType}($oNode);
        break;
      //these are not implemented (es6)
      case "ArrayPattern":
      case "ObjectPattern":
      case "Property":
        throw new Exception("unsupported: \"" . $sType . "\"");
        break;

      default:
        throw new Exception("unknown node type: \"" . $sType . "\"");
    }

    return $sResult;
  }
  
  public function fnEncodeProp($oNode) 
  {
    if ($oNode->bComputed) {
      //a[0] or a[b] or a[b + 1]
      return $this->fnGenerate($oNode->oProperty);
    } else {
      //a.b
      return $this->fnEncodeLiteral($oNode->oProperty->sName);
    }
  }
          
  public function fnIndent($iCount=0) 
  {
    $iIndentLevel = $this->aOpts["indentLevel"] + $iCount;
    return str_repeat("  ", $iIndentLevel);
  }
  
  public function fnProcess()
  {
    $this->aOpts['initVars'] = $this->aOpts['initVars'] !== false;
    $oRootNode = $this->fnParse($this->aOpts['source']);
    
    return $this->fnGenerate($oRootNode);
  }
  
  public function fnParse($sSource)
  {
    $oRootNode = Parser::fnParse($sSource);
    
    $this->fnTransform($oAST);
    
    return $oRootNode;
  }

  public function fnHandlerNewExpression($oNode) 
  {
    if ($oNode->oCallee->sType === 'Identifier' && $oNode->oCallee->sName === 'Function') {
      $aArgs = $oNode->aArguments;
      //ensure all arguments are string literals
      for ($iI = 0, $iLen = count($aArgs); $iI < $iLen; $iI++) {
        $oArg = $aArgs[$iI];
        if ($oArg->sType !== 'Literal' || !Is_string($oArg->mValue)) {
          throw new Exception('Parse Error: new Function() not supported except with string literal');
        }
      }
      $aArgs = array_map(function($v) { return $v->mValue; }, $aArgs);
      $sBody = array_pop($aArgs);
      $sCode = '(function(' . join(', ', $aArgs) . ') {' . $sBody . '})';
      $oAST = $this->fnParse($sCode);
      $oNewNode = $oAST->aBody[0]->oExpression;
      $oNewNode->bUseStrict = false;
      $this->fnReplaceNode($oNode, $oNewNode);
    }
  }
          
  public function fnHandlerVariableDeclaration($oNode) 
  {
    $oScope = &$this->fnGetParentScope($oNode);
    if (!$oScope->aVars) {
      $oScope->aVars = [];
    }
    foreach ($oNode->aDeclarations as $oDecl) {
      $oScope->aVars[$oDecl->oId->sName] = true;
    }
  }
  
  public function fnHandlerFunctionDeclaration($oNode) 
  {
    $sName = $oNode->oId->sName;
    $oScope = &$this->fnGetParentScope($oNode);
    if (!$oScope->aFuncs) {
      $oScope->aFuncs = [];
    }
    $oScope->aFuncs[$sName] = &$oNode;
  }
  
  public function fnHandlerBinaryExpression($oNode) 
  {
    if ($oNode->sOperator === '+') {
      $aTerms = $this->fnGetTerms($oNode, '+');
      $bIsConcat = false;

      foreach ($aTerms as $oTerm) {
        if ($oTerm->sType == 'Literal' && is_string($oTerm->mValue)) {
          $bIsConcat = true;
          break;
        }
      }

      $oNode->aTerms = $aTerms;
      $oNode->bIsConcat = $bIsConcat;
    }
  }

  public function fnGetTerms($oNode, $sOp) 
  {
    $aTerms = [];
    if ($oNode->oLeft->sType === 'BinaryExpression' && $oNode->oLeft->sOperator === $sOp) {
      $aTerms = array_merge($aTerms, $this->fnGetTerms($oNode->oLeft, $sOp));
    } else {
      array_push($aTerms, $oNode->oLeft);
    }
    if (node.right.type === 'BinaryExpression' && node.right.operator === op) {
      $aTerms = array_merge($aTerms, $this->fnGetTerms($oNode->oRight, $sOp));
    } else {
      array_push($aTerms, $oNode->oRight);
    }
    return $aTerms;
  }
  
  public function fnTransform(&$oAST)
  {
    
  }
  
  public function fnIndexScope(&$oScope)
  {
    
  }
}
