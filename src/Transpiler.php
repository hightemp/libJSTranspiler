<?php

namespace libJSTranspiler;

use libJSTranspiler\Parser;
use libJSTranspiler\Utilities;

class Transpiler
{
  const BOOL_SAFE_OPS = [
    '===', '!==',
    '==', '!=',
    '<', '<=',
    '>', '>=',
    'in',
    'instanceof'
  ];
  
  const PRECEDENCE = [
    'SEQUENCE' => 0,
    'YIELD' => 1,
    'ASSIGNMENT' => 1,
    'CONDITIONAL' => 2,
    'ARROW_FUNCTION' => 2,
    'LOGICAL_OR' => 3,
    'LOGICAL_AND' => 4,
    'BITWISE_OR' => 5,
    'BITWISE_XOR' => 6,
    'BITWISE_AND' => 7,
    'EQUALITY' => 8,
    'RELATIONAL' => 9,
    'BITWISE_SHIFT' => 10,
    'ADDITIVE' => 11,
    'MULTIPLICATIVE' => 12,
    'UNARY' => 13,
    'POSTFIX' => 14,
    'CALL' => 15,
    'NEW' => 16,
    'TAGGED_TEMPLATE' => 17,
    'MEMBER' => 18,
    'PRIMARY' => 19      
  ];
  
  const BINARY_PRECEDENCE = [
    '||' => self::PRECEDENCE['LOGICAL_OR'],
    '&&' => self::PRECEDENCE['LOGICAL_AND'],
    '|' => self::PRECEDENCE['BITWISE_OR'],
    '^' => self::PRECEDENCE['BITWISE_XOR'],
    '&' => self::PRECEDENCE['BITWISE_AND'],
    '==' => self::PRECEDENCE['EQUALITY'],
    '!=' => self::PRECEDENCE['EQUALITY'],
    '===' => self::PRECEDENCE['EQUALITY'],
    '!==' => self::PRECEDENCE['EQUALITY'],
    '<' => self::PRECEDENCE['RELATIONAL'],
    '>' => self::PRECEDENCE['RELATIONAL'],
    '<=' => self::PRECEDENCE['RELATIONAL'],
    '>=' => self::PRECEDENCE['RELATIONAL'],
    'in' => self::PRECEDENCE['RELATIONAL'],
    'instanceof' => self::PRECEDENCE['RELATIONAL'],
    '<<' => self::PRECEDENCE['BITWISE_SHIFT'],
    '>>' => self::PRECEDENCE['BITWISE_SHIFT'],
    '>>>' => self::PRECEDENCE['BITWISE_SHIFT'],
    '+' => self::PRECEDENCE['ADDITIVE'],
    '-' => self::PRECEDENCE['ADDITIVE'],
    '*' => self::PRECEDENCE['MULTIPLICATIVE'],
    '%' => self::PRECEDENCE['MULTIPLICATIVE'],
    '/' =>self::PRECEDENCE['MULTIPLICATIVE']
  ];

  //these operators expect numbers
  const UNARY_NUM_OPS = [
    '-',
    '+',
    '~' //bitwise not
  ];

  //these operators expect numbers
  const BINARY_NUM_OPS = [
    // `+` is not in this list because it's a special case
    '-',
    '%',
    '*',
    '/',
    // let's assume these comparison operators are only used on numbers
    //'<', '<=',
    //'>', '>=',
    '&', //bitwise and
    '|', //bitwise or
    '^', //bitwise xor
    '<<', //bitwise left shift
    '>>', //bitwise sign-propagating right shift
    '>>>' //bitwise zero-fill right shift
  ];
  
  const NUM_SAFE_UNARY_OPS = [
    '-',
    '+',
    '~'
  ];
  
  const NUM_SAFE_BINARY_OPS = [
    // `+` is not in this list because it's a special case
    '-',
    '%',
    '*', '/',
    '&', '|',
    '^',
    '<<', '>>',
    '>>>'
  ];
  
  public $aOpts;
  
  public function __construct($aOpts=[])
  {
    $this->aOpts = $aOpts;
  }

  public function fnBody($oNode, &$oParent) 
  {
    $aOpts = $this->aOpts;
    $oScopeNode = ($oNode->sType === 'BlockStatement') ? $oParent : $oNode;
    $oScopeIndex = $oScopeNode->oScopeIndex;
    $aResults = [];
    
    $aOpts['indentLevel'] += 1;
    
    if (scopeIndex.thisFound) {
      if ($oNode->sType === 'Program') {
        array_push($aResults, $this->fnIndent() . '$this_ = $global;\n');
      } else {
        array_push($aResults, $this->fnIndent() . '$this_ = Func::getContext();\n');
      }
    }
    if (scopeIndex.argumentsFound && $oNode->sType !== 'Program') {
      array_push($aResults, $this->fnIndent() . '$arguments = Func::getArguments();\n');
    }
    if ($oNode->aVars && $aOpts['initVars']) {
      $aDeclarations = [];
      foreach($oNode->aVars as $sName => $sValue) {
        array_push($aDeclarations, $this->fnEncodeVarName($sName) . ' = null;');
      };
      if (declarations.length) {
        array_push($aResults, $this->fnIndent() . join(' ', $aDeclarations) . '\n');
      }
    }
    $aFuncDeclarations = $oNode->aFuncs;
    if ($aFuncDeclarations) {
      foreach($aFuncDeclarations as $sName => $sValue) {
        $sFunc = $this->fnFunctionExpression($aFuncDeclarations[$sName]);
        array_push($aResults, $this->fnIndent() . $this->fnEncodeVarName($sName) . ' = ' . $sFunc . ';\n');
      };
    }

    foreach($oNode->aBody as $oNode) {
      $sResult = $this->fnGenerate($oNode, $oParent);
      if ($sResult) {
        array_push($aResults, $this->fnIndent() . $sResult);
      }
    }
    
    if ($aOpts['indentLevel'] > 0) {
      $aOpts['indentLevel'] -= 1;
    }
    return join('', $aResults);
  }
  
  public function fnIsStrictDirective($oStmt)
  {
    if ($oStmt && $oStmt->sType === 'ExpressionStatement') {
      $oExpr = $oStmt->oExpression;
      if ($oExpr->sType === 'Literal' && $oExpr->mValue === 'use strict') {
        return true;
      }
    }
    return false;
  }
  
  public function fnIsBooleanExpr($oNode) 
  {
    if ($oNode->sType === 'LogicalExpression') {
      //prevent unnecessary (is($and_ = $a === $b) ? $c === $d : $and_)
      return $this->fnIsBooleanExpr($oNode->oLeft) && $this->fnIsBooleanExpr($oNode->oRight);
    }
    if ($oNode->sType === 'Literal' && is_bool($oNode->mValue)) {
      //prevent is(true)
      return true;
    }
    if ($oNode->sType === 'BinaryExpression' && in_array($oNode->sOperator, self::BOOL_SAFE_OPS)) {
      //prevent is($a === $b) and is(_in($a, $b))
      return true;
    }
    if ($oNode->sType === 'UnaryExpression' && $oNode->sOperator === '!') {
      //prevent is(not($thing))
      return true;
    }
    return false;
  }

  // used to determine when we can omit to_number() so as to prevent stuff
  //  like: to_number(5.0 - 2.0) - 1.0;
  public function fnIsNumericExpr($oNode) 
  {
    if ($oNode->sType === 'Literal' && is_numeric($oNode->mValue)) {
      return true;
    }
    if ($oNode->sType === 'UnaryExpression' && in_array($oNode->sOperator, self::NUM_SAFE_UNARY_OPS)) {
      return true;
    }
    if ($oNode->sType === 'BinaryExpression' && in_array($oNode->sOperator, self::NUM_SAFE_BINARY_OPS)) {
      return true;
    }
  }

  public function fnEncodeLiteral($mValue) 
  {
    $sType = gettype($mValue);
    
    if (!isset($mValue)) {
      return 'null';
    }
    if ($sType === 'NULL') {
      return 'Object::$null';
    }
    if ($sType === 'string') {
      return $this->fnEncodeString($mValue);
    }
    if ($sType === 'boolean') {
      return $mValue ? 'true' : 'false';
    }
    if ($sType === 'integer' || $sType === 'double') {
      return $mValue;
    }
    
    throw new Exception('No handler for literal of type: ' . $sType . ': ' . $mValue);
  }

  public function fnEncodeRegExp($mValue) 
  {
    $sFlags = '';
    if (value.global) flags += 'g';
    if (value.ignoreCase) flags += 'i';
    if (value.multiline) flags += 'm';
    //normalize source to ensure no forward slashes are "escaped"
    var source = value.source.replace(/\\./g, function(s) {
      return (s === '\\/') ? '/' : s;
    });
    return 'new RegExp(' + encodeString(source) + ', ' + encodeString(flags) + ')';
  }

  public function fnEncodeString($mValue) {
    return Utilities::fnEncodeString($mValue);
  }

  public function fnEncodeVar(identifier) {
    var name = identifier.name;
    return encodeVarName(name, identifier.appendSuffix);
  }

  public function fnEncodeVarName($sName, $sSuffix) {
    return Utilities::fnEncodeVarName($sName, $sSuffix);
  }

  public function fnIsWord($sStr) {
    return preg_match("/^\w+$/", $sStr);
  }

  public function fnOpPrecedence($sOp) {
    return self::BINARY_PRECEDENCE[$sOp];
  }
  
  public function fnGenerate($oNode, &$oParent=null)
  {
    $aOpts = $this->aOpts;
    if (!isset($aOpts['indentLevel']) || !$aOpts['indentLevel']) {
      $aOpts['indentLevel'] = -1;
    }
    //we might get a null call `for (; i < l; i++) { ... }`
    if ($oNode === null) {
      return '';
    }
    $sType = $oNode->sType;
    $sResult;
    switch ($sType) {
      //STATEMENTS
      case 'Program':
        $aOpts['isStrict'] = $this->fnIsStrictDirective($oNode->aBody[0]);
        $sResult = $this->fnBody($oNode, $oParent);
        break;
      case 'ExpressionStatement':
        $sResult = $this->fnIsStrictDirective($oNode) ? '' : $this->fnGenerate($oNode->oExpression, $oNode) . ';\n';
        break;
      case 'ReturnStatement':
        $sResult = 'return ' . $this->fnGenerate($oNode->oArgument, $oNode) . ';\n';
        break;
      case 'ContinueStatement':
        $sResult = 'continue;\n';
        break;
      case 'BreakStatement':
        $sResult = 'break;\n';
        break;
      case 'EmptyStatement':
      case 'DebuggerStatement':
      //this is handled at beginning of parent scope
      case 'FunctionDeclaration':
        $sResult = '';
        break;
      case 'VariableDeclaration':
      case 'IfStatement':
      case 'SwitchStatement':
      case 'ForStatement':
      case 'ForInStatement':
      case 'WhileStatement':
      case 'DoWhileStatement':
      case 'BlockStatement':
      case 'TryStatement':
      case 'ThrowStatement':
        $sResult = $this->{"fn".$sType}($oNode);
        break;
      //these should never be encountered here because they are handled elsewhere
      case 'SwitchCase':
      case 'CatchClause':
        throw new Exception('should never encounter: "' . $sType . '"');
        break;
      //these are not implemented (some are es6, some are irrelevant)
      case 'DirectiveStatement':
      case 'ForOfStatement':
      case 'LabeledStatement':
      case 'WithStatement':
        throw new Exception('unsupported: "' . $sType . '"');
        break;

      //EXPRESSIONS
      case 'Literal':
        $sResult = $this->fnEncodeLiteral($oNode->mValue);
        break;
      case 'Identifier':
        $sResult = $this->fnEncodeVar($oNode);
        break;
      case 'ThisExpression':
        $sResult = '$this_';
        break;
      case 'FunctionExpression':
      case 'AssignmentExpression':
      case 'CallExpression':
      case 'MemberExpression':
      case 'NewExpression':
      case 'ArrayExpression':
      case 'ObjectExpression':
      case 'UnaryExpression':
      case 'BinaryExpression':
      case 'LogicalExpression':
      case 'SequenceExpression':
      case 'UpdateExpression':
      case 'ConditionalExpression':
        $sResult = $this->{"fn".$sType}($oNode);
        break;
      //these are not implemented (es6)
      case 'ArrayPattern':
      case 'ObjectPattern':
      case 'Property':
        throw new Exception('unsupported: "' . $sType . '"');
        break;

      default:
        throw new Exception('unknown node type: "' . $sType . '"');
    }

    return $sResult;
  }
  
  public function fnIndent($iCount=0) 
  {
    $iIndentLevel = $this->aOpts['indentLevel'] + $iCount;
    return str_repeat('  ', $iIndentLevel);
  }
   
}
