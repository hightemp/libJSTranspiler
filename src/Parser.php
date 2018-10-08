<?php

namespace libJSTranspiler;

use libJSTranspiler\Node;
use libJSTranspiler\Token;
use libJSTranspiler\TokenType;
use libJSTranspiler\TokenTypes;
use libJSTranspiler\Identifier;
use libJSTranspiler\Whitespace;
use libJSTranspiler\TokenContext;
use libJSTranspiler\TokenContextTypes;
use libJSTranspiler\Utilities;
use libJSTranspiler\Scope;
use libJSTranspiler\Label;
use libJSTranspiler\DestructuringErrors;
use Closure;
use Exception;

if (!function_exists('fnKeywordRegexp')) {
  function fnKeywordRegexp($sString)
  {
    return "/^(?:" . str_replace(" ", "|", $sString) . ")$/";
  }
}

class InvalidTemplateEscapeError extends Exception { }

class Parser
{
  public $aOptions;
  public $sSourceFile;
  public $sKeywords;
  public $sReservedWords;
  public $sReservedWordsStrict;
  public $sReservedWordsStrictBind;
  public $sInput;
  public $bContainsEsc;
  public $iPos;
  public $iLineStart;
  public $iCurLine;
  public $oType;
  public $mValue;
  public $iStart;
  public $iEnd;
  public $oStartLoc;
  public $oEndLoc;
  public $oLastTokEndLoc;
  public $oLastTokStartLoc;
  public $iLastTokStart;
  public $iLastTokEnd;
  public $aContext;
  public $aDeclarations;
  public $aLabels;
  public $aScopeStack;
  public $bExprAllowed;
  public $bInModule;
  public $iYieldPos;
  public $iAwaitPos;
  public $oRegexpState;
  public $bStrict;
  public $iPotentialArrowAt;
  
  public $oLoopLabel;
  public $oSwitchLabel;

  const FUNC_STATEMENT = 1;
  const FUNC_HANGING_STATEMENT = 2;
  const FUNC_NULLABLE_ID = 4;

  public static $aDefaultOptions = [
    // `ecmaVersion` indicates the ECMAScript version to parse. Must be
    // either 3, 5, 6 (2015), 7 (2016), 8 (2017), 9 (2018), or 10
    // (2019). This influences support for strict mode, the set of
    // reserved words, and support for new syntax features. The default
    // is 9.
    "ecmaVersion" => 9,
    // `sourceType` indicates the mode the code should be parsed in.
    // Can be either `"script"` or `"module"`. This influences global
    // strict mode and parsing of `import` and `export` declarations.
    "sourceType" => "script",
    // `onInsertedSemicolon` can be a callback that will be called
    // when a semicolon is automatically inserted. It will be passed
    // th position of the comma as an offset, and if `locations` is
    // enabled, it is given the location as a `{line, column}` object
    // as second argument.
    "onInsertedSemicolon" => null,
    // `onTrailingComma` is similar to `onInsertedSemicolon`, but for
    // trailing commas.
    "onTrailingComma" => null,
    // By default, reserved words are only enforced if ecmaVersion >= 5.
    // Set `allowReserved` to a boolean value to explicitly turn this on
    // an off. When this option has the value "never", reserved words
    // and keywords can also not be used as property names.
    "allowReserved" => null,
    // When enabled, a return at the top level is not considered an
    // error.
    "allowReturnOutsideFunction" => false,
    // When enabled, import/export statements are not constrained to
    // appearing at the top of the program.
    "allowImportExportEverywhere" => false,
    // When enabled, await identifiers are allowed to appear at the top-level scope,
    // but they are still not allowed in non-async functions.
    "allowAwaitOutsideFunction" => false,
    // When enabled, hashbang directive in the beginning of file
    // is allowed and treated as a line comment.
    "allowHashBang" => false,
    // When `locations` is on, `loc` properties holding objects with
    // `start` and `end` properties in `{line, column}` form (with
    // line being 1-based and column 0-based) will be attached to the
    // nodes.
    "locations" => false,
    // A function can be passed as `onToken` option, which will
    // cause Acorn to call that function with object in the same
    // format as tokens returned from `tokenizer().getToken()`. Note
    // that you are not allowed to call the parser from the
    // callback—that will corrupt its internal state.
    "onToken" => null,
    // A function can be passed as `onComment` option, which will
    // cause Acorn to call that function with `(block, text, start,
    // end)` parameters whenever a comment is skipped. `block` is a
    // boolean indicating whether this is a block (`/* */`) comment,
    // `text` is the content of the comment, and `start` and `end` are
    // character offsets that denote the start and end of the comment.
    // When the `locations` option is on, two more parameters are
    // passed, the full `{line, column}` locations of the start and
    // end of the comments. Note that you are not allowed to call the
    // parser from the callback—that will corrupt its internal state.
    "onComment" => null,
    // Nodes have their start and end characters offsets recorded in
    // `start` and `end` properties (directly on the node, rather than
    // the `loc` object, which holds line/column data. To also add a
    // [semi-standardized][range] `range` property holding a `[start,
    // end]` array with the same numbers, set the `ranges` option to
    // `true`.
    //
    // [range]: https://bugzilla.mozilla.org/show_bug.cgi?id=745678
    "ranges" => false,
    // It is possible to parse multiple files into a single AST by
    // passing the tree produced by parsing the first file as
    // `program` option in subsequent parses. This will add the
    // toplevel forms of the parsed file to the `Program` (top) node
    // of an existing parse tree.
    "program" => null,
    // When `locations` is on, you can pass this to record the source
    // file in every node's `loc` object.
    "sourceFile" => null,
    // This value, if given, is stored in every node, whether
    // `locations` is on or off.
    "directSourceFile" => null,
    // When enabled, parenthesized expressions are represented by
    // (non-standard) ParenthesizedExpression nodes
    "preserveParens" => false
  ];
  
  public function __construct($aOptions, $sInput, $iStartPos=0)
  {
    $this->aOptions = $aOptions = array_merge(self::$aDefaultOptions, $aOptions);

    if ($aOptions['ecmaVersion'] >= 2015)
      $aOptions['ecmaVersion'] -= 2009;

    if ($aOptions['allowReserved'] == null)
      $aOptions['allowReserved'] = $aOptions['ecmaVersion'] < 5;

    if (is_array($aOptions['onToken'])) {
      $aTokens = $aOptions['onToken'];
      $aOptions['onToken'] = function ($oToken) use (&$aTokens)
      {
        array_push($aTokens, $oToken);
      };
    }
    if (is_array($aOptions['onComment'])) {
      $aOptions['onComment'] = function($bBlock, $sText, $iStart, $iEnd, $iStartLoc, $iEndLoc) use (&$aOptions)
      {
        $aComment = [
          'type' => $bBlock ? "Block" : "Line",
          'value' => $sText,
          'start' => $iStart,
          'end' => $iEnd,
        ];
        if ($aOptions['locations'])
          $aOptions['loc'] = new SourceLocation($this, $iStartLoc, $iEndLoc);
        if ($aOptions['ranges'])
          $aOptions['range'] = [$iStart, $iEnd];
        array_push($aOptions['onComment'], $aComment);
      };
    }
    
    $this->sSourceFile = $aOptions['sourceFile'];
    $this->sKeywords = fnKeywordRegexp(Identifier::keywords[$aOptions['ecmaVersion'] >= 6 ? 6 : 5]);
    
    $sReserved = "";
    if (!$aOptions['allowReserved']) {
      for ($iV = $aOptions['ecmaVersion'];$iV; $iV--)
        if (isset(Identifier::$aReservedWords[$iV])) {
          $sReserved = Identifier::$aReservedWords[$iV];
          break;
        }
      if ($aOptions['sourceType'] === "module") 
        $sReserved .= " await";
    }
    $this->sReservedWords = fnKeywordRegexp($sReserved);
    $sReservedStrict = ($sReserved ? $sReserved . " " : "") . Identifier::$aReservedWords['strict'];
    $this->sReservedWordsStrict = fnKeywordRegexp($sReservedStrict);
    $this->sReservedWordsStrictBind = fnKeywordRegexp($sReservedStrict . " " . Identifier::$aReservedWords['strictBind']);
    $this->sInput = $sInput;

    // Used to signal to callers of `readWord1` whether the word
    // contained any escape sequences. This is needed because words with
    // escape sequences must not be interpreted as keywords.
    $this->bContainsEsc = false;

    // Set up token state

    // The current position of the tokenizer in the input.
    if ($iStartPos) {
      $this->iPos = $iStartPos;
      $this->iLineStart = strrpos("\n", $this->sInput, $iStartPos - 1) + 1;      
      $this->iCurLine = count(preg_split(
        Whitespace::lineBreak, 
        substr($this->input, 0, $this->lineStart)
      ));
    } else {
      $this->iPos = $this->iLineStart = 0;
      $this->iCurLine = 1;
    }

    // Properties of the current token:
    // Its type
    $this->oType = TokenTypes::$aTypes["eof"];
    // For tokens that include more information than their type, the value
    $this->mValue = null;
    // Its start and end offset
    $this->iStart = $this->iEnd = $this->iPos;
    // And, if locations are used, the {line, column} object
    // corresponding to those offsets
    $this->oStartLoc = $this->oEndLoc = $this->fnCurPosition();

    // Position information for the previous token
    $this->oLastTokEndLoc = $this->oLastTokStartLoc = null;
    $this->iLastTokStart = $this->iLastTokEnd = $this->iPos;

    // The context stack is used to superficially track syntactic
    // context to predict whether a regular expression is allowed in a
    // given position.
    $this->aContext = $this->fnInitialContext();
    $this->bExprAllowed = true;

    // Figure out if it's a module code.
    $this->bInModule = $aOptions['sourceType'] === "module";
    $this->bStrict = $this->bInModule || $this->fnStrictDirective($this->iPos);

    // Used to signify the start of a potential arrow function
    $this->iPotentialArrowAt = -1;

    // Positions to delayed-check that yield/await does not exist in default parameters.
    $this->iYieldPos = $this->iAwaitPos = 0;
    // Labels in scope.
    $this->aLabels = [];

    // If enabled, skip leading hashbang line.
    if ($this->iPos === 0 && $aOptions['allowHashBang'] && mb_substr($this->sInput, 0, 2) === "#!")
      $this->fnSkipLineComment(2);

    // Scope tracking for duplicate variable names (see scope.js)
    $this->aScopeStack = [];
    $this->fnEnterScope(Scope::SCOPE_TOP);

    // For RegExp validation
    $this->regexpState = null;
    
    $this->oLoopLabel = new Label();
    $this->oLoopLabel->sKind = "loop";
    $this->oSwitchLabel = new Label();
    $this->oSwitchLabel->sKind = "switch";
  }

  public function fnParseTopLevel(&$oNode)
  {
    $aExports = [];
    if (!$oNode->aBody)
      $oNode->aBody = [];
    while ($this->oType !== TokenTypes::$aTypes['eof']) {
      $mStmt = $this->fnParseStatement(null, true, $aExports);
      array_push($oNode->aBody, $mStmt);
    }
    $this->fnAdaptDirectivePrologue($oNode->aBody);
    $this->fnNext();
    if ($this->aOptions['ecmaVersion'] >= 6) {
      $oNode->sSourceType = $this->aOptions['sourceType'];
    }
    return $this->fnFinishNode($oNode, "Program");
  }

  public function fnIsLet()
  {
    if ($this->aOptions['ecmaVersion'] < 6 
        || !$this->fnIsContextual("let")) 
      return false;
    preg_match(Whitespace::skipWhiteSpace, $this->sInput, $aMatches, 0, $this->iPos);
    //skipWhiteSpace.lastIndex = $this->pos
    //let skip = skipWhiteSpace.exec($this->input)
    $iNext = $this->iPos + count($aMatches[0]);
    $iNextCh = Utilities::fnGetCharCodeAt($this->sInput, $iNext);
    //nextCh = $this->input.charCodeAt(next)
    if ($iNextCh === 91 || $iNextCh === 123) 
      return true; // '{' and '['
    if (Identifier::fnIsIdentifierStart($iNextCh, true)) {
      $iPos = $iNext + 1;
      while (Identifier::fnIsIdentifierChar(Utilities::fnGetCharCodeAt($this->sInput, $iPos), true)) 
        ++$iPos;
      $sIdent = mb_substr($this->sInput, $iNext, $iPos-$iNext);
      if (!keywordRelationalOperator.test(ident)) 
        return true;
    }
    return false;
  }

  // check 'async [no LineTerminator here] function'
  // - 'async /*foo*/ function' is OK.
  // - 'async /*\n*/ function' is invalid.
  public function fnIsAsyncFunction()
  {
    if ($this->aOptions['ecmaVersion'] < 8 || !$this->fnIsContextual("async"))
      return false;

    //skipWhiteSpace.lastIndex = $this->pos
    //let skip = skipWhiteSpace.exec($this->input)
    preg_match(Whitespace::skipWhiteSpace, $this->sInput, $aMatches, 0, $this->iPos);    
    $iNext = $this->iPos + count($aMatches[0]);
    return !preg_match(
      Whitespace::lineBreak, 
      mb_substr($this->sInput, $this->iPos, $iNext - $this->iPos)
    ) 
    && mb_substr($this->sInput, $iNext, 8) === "function"
    && ($iNext + 8 === count($this->sInput) 
        || !Identifier::fnIsIdentifierChar(Utilities::fnGetCharAt($this->sInput, $iNext + 8)));
    /*
    return !lineBreak.test($this->input.slice($this->pos, next)) &&
      $this->input.slice(next, next + 8) === "function" &&
      (next + 8 === $this->input.length || !isIdentifierChar($this->input.charAt(next + 8)))
     */
  }

  // Parse a single statement.
  //
  // If expecting a statement and finding a slash operator, parse a
  // regular expression literal. This is to handle cases like
  // `if (foo) /blah/.exec(foo)`, where looking at the previous token
  // does not help.

  public function fnParseStatement($sContext, $bTopLevel, $mExports)
  {
    $oStarttype = $this->oType;
    $oNode = $this->fnStartNode();
    $sKind;

    if ($this->fnIsLet()) {
      $oStarttype = TokenTypes::$aTypes['var'];
      $sKind = "let";
    }

    // Most types of statements are recognized by the keyword they
    // start with. Many are trivial to parse, some require a bit of
    // complexity.

    switch ($oStarttype) {
      case TokenTypes::$aTypes['break']: 
      case TokenTypes::$aTypes['continue']: 
        return $this->fnParseBreakContinueStatement($oNode, $oStarttype->sKeyword);
      case TokenTypes::$aTypes['debugger']: 
        return $this->parseDebuggerStatement($oNode);
      case TokenTypes::$aTypes['do']: 
        return $this->parseDoStatement($oNode);
      case TokenTypes::$aTypes['for']: 
        return $this->parseForStatement($oNode);
      case TokenTypes::$aTypes['function']:
        if (($sContext && ($this->bStrict || $sContext !== "if")) 
            && $this->aOptions['ecmaVersion'] >= 6) 
          $this->fnUnexpected();
        return $this->fnParseFunctionStatement($oNode, false, !$sContext);
      case TokenTypes::$aTypes['class']:
        if ($sContext) 
          $this->fnUnexpected();
        return $this->fnParseClass($oNode, true);
      case TokenTypes::$aTypes['if']: 
        return $this->fnParseIfStatement($oNode);
      case TokenTypes::$aTypes['return']: 
        return $this->fnParseReturnStatement($oNode);
      case TokenTypes::$aTypes['switch']: 
        return $this->fnParseSwitchStatement($oNode);
      case TokenTypes::$aTypes['throw']: 
        return $this->fnParseThrowStatement($oNode);
      case TokenTypes::$aTypes['try']: 
        return $this->fnParseTryStatement($oNode);
      case TokenTypes::$aTypes['const']: 
      case TokenTypes::$aTypes['var']:
        $sKind = !empty($sKind) ? $sKind : $this->mValue;
        if ($sContext && $sKind !== "var") 
          $this->fnUnexpected();
        return $this->fnParseVarStatement($oNode, $sKind);
      case TokenTypes::$aTypes['while']: 
        return $this->fnParseWhileStatement($oNode);
      case TokenTypes::$aTypes['with']: 
        return $this->fnParseWithStatement($oNode);
      case TokenTypes::$aTypes['braceL']: 
        return $this->fnParseBlock(true, $oNode);
      case TokenTypes::$aTypes['semi']: 
        return $this->fnParseEmptyStatement($oNode);
      case TokenTypes::$aTypes['export']:
      case TokenTypes::$aTypes['import']:
        if (!$this->aOptions['allowImportExportEverywhere']) {
          if (!$bTopLevel)
            $this->fnRaise($this->iStart, "'import' and 'export' may only appear at the top level");
          if (!$this->bInModule)
            $this->fnRaise($this->iStart, "'import' and 'export' may appear only with 'sourceType: module'");
        }
        return $oStarttype === TokenTypes::$aTypes['import'] ? 
          $this->fnParseImport($oNode) : 
          $this->fnParseExport($oNode, $mExports);

        // If the statement does not start with a statement keyword or a
        // brace, it's an ExpressionStatement or LabeledStatement. We
        // simply start parsing an expression, and afterwards, if the
        // next token is a colon and the expression was a simple
        // Identifier node, we switch to interpreting it as a label.
      default:
        if ($this->fnIsAsyncFunction()) {
          if ($sContext) 
            $this->fnUnexpected();
          $this->fnNext();
          return $this->fnParseFunctionStatement($oNode, true, !$sContext);
        }

        $sMaybeName = $this->mValue;
        $oExpr = $this->fnParseExpression();
        
        if ($oStarttype === TokenTypes::$aTypes['name'] 
            && $oExpr->sType === "Identifier" 
            && $this->fnEat(TokenTypes::$aTypes['colon']))
          return $this->fnParseLabeledStatement($oNode, $sMaybeName, $oExpr, $sContext);
        else 
          return $this->fnParseExpressionStatement($oNode, $oExpr);
    }
  }

  public function fnParseBreakContinueStatement(&$oNode, $sKeyword)
  {
    $bIsBreak = $sKeyword === "break";
    
    $this->fnNext();
    if ($this->fnEat(TokenTypes::$aTypes['semi']) 
        || $this->fnInsertSemicolon()) 
      $oNode->oLabel = null;
    else if ($this->oType !== TokenTypes::$aTypes['name']) 
      $this->fnUnexpected();
    else {
      $oNode->oLabel = $this->fnParseIdent();
      $this->fnSemicolon();
    }

    // Verify that there is an actual destination to break or
    // continue to.
    $iI = 0;
    for (; $iI < count($this->aLabels); ++$iI) {
      $oLab = $this->aLabels[$iI];
      if ($oNode->oLabel == null || $oLab->sName === $oNode->oLabel->sName) {
        if ($oLab->sKind != null && ($bIsBreak || $oLab->sKind === "loop")) 
          break;
        if ($oNode->oLabel && $bIsBreak) 
          break;
      }
    }
    if ($iI === count($this->aLabels)) 
      $this->fnRaise($oNode->iStart, "Unsyntactic " . $sKeyword);
    return 
      $this->fnFinishNode($oNode, $bIsBreak ? "BreakStatement" : "ContinueStatement");
  }

  public function fnParseDebuggerStatement($oNode)
  {
    $this->fnNext();
    $this->fnSemicolon();
    return $this->fnFinishNode($oNode, "DebuggerStatement");
  }

  public function fnParseDoStatement(&$oNode)
  {
    $this->fnNext();
    array_push($this->aLabels, $this->oLoopLabel);
    //$this->labels.push(loopLabel)
    $oNode->aBody = $this->fnParseStatement("do");
    array_pop($this->aLabels);
    $this->fnExpect(TokenTypes::$aTypes['while']);
    $oNode->oTest = $this->fnParseParenExpression(); //?
    if ($this->aOptions['ecmaVersion'] >= 6)
      $this->fnEat(TokenTypes::$aTypes['semi']);
    else
      $this->fnSemicolon();
    return $this->fnFinishNode($oNode, "DoWhileStatement");
  }

  // Disambiguating between a `for` and a `for`/`in` or `for`/`of`
  // loop is non-trivial. Basically, we have to parse the init `var`
  // statement or expression, disallowing the `in` operator (see
  // the second parameter to `parseExpression`), and then check
  // whether the next token is `in` or `of`. When there is no init
  // part (semicolon immediately after the opening parenthesis), it
  // is a regular `for` loop.

  public function fnParseForStatement(&$oNode)
  {
    $this->fnNext();
    $iAwaitAt = (
        $this->aOptions['ecmaVersion'] >= 9 
        && ($this->fnInAsync() 
            || (!$this->fnInFunction() 
                && $this->aOptions['allowAwaitOutsideFunction'])) 
            && $this->fnEatContextual("await")
      ) ? 
      $this->iLastTokStart : 
      -1;
    array_push($this->aLabels, $this->oLoopLabel);
    //$this->labels.push(loopLabel)
    $this->fnEnterScope(0);
    $this->fnExpect(TokenTypes::$aTypes['parenL']);
    
    if ($this->oType === TokenTypes::$aTypes['semi']) {
      if ($iAwaitAt > -1) 
        $this->fnUnexpected($iAwaitAt);
      return $this->parseFor($oNode, null);
    }
    
    $bIsLet = $this->fnIsLet();
    if ($this->oType === TokenTypes::$aTypes['var'] 
        || $this->oType === TokenTypes::$aTypes['const'] 
        || $bIsLet) {
      $oInit = $this->fnStartNode();
      $sKind = $bIsLet ? "let" : $this->mValue;
      $this->fnNext();
      $this->fnParseVar($oInit, true, $sKind);
      $this->fnFinishNode($oInit, "VariableDeclaration");
      if (($this->oType === TokenTypes::$aTypes['in'] 
           || ($this->aOptions['ecmaVersion'] >= 6 
              && $this->fnIsContextual("of"))) 
          && count($oInit->aDeclarations) === 1 
          && !($sKind !== "var" && $oInit->aDeclarations[0]->init)) {
        if ($this->aOptions['ecmaVersion'] >= 9) {
          if ($this->oType === TokenTypes::$aTypes['in']) {
            if ($iAwaitAt > -1) 
              $this->fnUnexpected($iAwaitAt);
          } else 
            $oNode->bAwait = $iAwaitAt > -1;
        }
        return $this->parseForIn($oNode, $oInit);
      }
      if ($iAwaitAt > -1) 
        $this->fnUnexpected($iAwaitAt);
      return $this->fnParseFor($oNode, $oInit);
    }
    
    $oRefDestructuringErrors = new DestructuringErrors();
    $oInit = $this->fnParseExpression(true, $oRefDestructuringErrors);
    if ($this->oType === TokenTypes::$aTypes['in'] 
        || ($this->aOptions['ecmaVersion'] >= 6 
            && $this->fnIsContextual("of"))) {
      if ($this->aOptions['ecmaVersion'] >= 9) {
        if ($this->oType === TokenTypes::$aTypes['in']) {
          if ($iAwaitAt > -1) 
            $this->fnUnexpected($iAwaitAt);
        } else 
          $oNode->bAwait = $iAwaitAt > -1;
      }
      $this->fnToAssignable($oInit, false, $oRefDestructuringErrors);
      $this->fnCheckLVal($oInit);
      return $this->fnParseForIn($oNode, $oInit);
    } else {
      $this->fnCheckExpressionErrors($oRefDestructuringErrors, true);
    }
    if ($iAwaitAt > -1) 
      $this->fnUnexpected($iAwaitAt);
    return $this->fnParseFor($oNode, $oInit);
  }

  public function fnParseFunctionStatement(&$oNode, $bIsAsync, $bDeclarationPosition)
  {
    $this->fnNext();
    return $this->fnParseFunction(
      $oNode, 
      self::FUNC_STATEMENT 
        | ($bDeclarationPosition ? 
            0 : 
            self::FUNC_HANGING_STATEMENT), 
      false, 
      $bIsAsync
    );
  }

  public function fnParseIfStatement(&$oNode)
  {
    $this->fnNext();
    $oNode->oTest = $this->fnParseParenExpression();
    // allow function declarations in branches, but only in non-strict mode
    $oNode->oConsequent = $this->fnParseStatement("if");
    $oNode->oAlternate = $this->fnEat(TokenTypes::$aTypes['else']) ? $this->fnParseStatement("if") : null;
    return $this->fnFinishNode($oNode, "IfStatement");
  }

  public function fnParseReturnStatement(&$oNode)
  {
    if (!$this->fnInFunction() 
        && !$this->aOptions['allowReturnOutsideFunction'])
      $this->fnRaise($this->iStart, "'return' outside of function");
    $this->fnNext();

    // In `return` (and `break`/`continue`), the keywords with
    // optional arguments, we eagerly look for a semicolon or the
    // possibility to insert one.

    if ($this->fnEat(TokenTypes::$aTypes['semi']) 
        || $this->fnInsertSemicolon()) 
      $oNode->oArgument = null;
    else { 
      $oNode->oArgument = $this->fnParseExpression(); 
      $this->fnSemicolon();
    }
    return $this->fnFinishNode($oNode, "ReturnStatement");
  }

  public function fnParseSwitchStatement(&$oNode)
  {
    $this->fnNext();
    $oNode->oDiscriminant = $this->fnParseParenExpression();
    $oNode->aCases = [];
    $this->fnExpect(TokenTypes::$aTypes['braceL']);
    array_push($this->aLabels, $this->oSwitchLabel);
    $this->fnEnterScope(0);

    // Statements under must be grouped (by label) in SwitchCase
    // nodes. `cur` is used to keep the node that we are currently
    // adding statements to.

    $oCur;
    for ($bSawDefault = false; $this->oType !== TokenTypes::$aTypes['braceR'];) {
      if ($this->oType === TokenTypes::$aTypes['case'] 
          || $this->oType === TokenTypes::$aTypes['default']) {
        $bIsCase = $this->oType === TokenTypes::$aTypes['case'];
        if ($oCur) 
          $this->fnFinishNode($oCur, "SwitchCase");
        array_push($oNode->aCases, $oCur = $this->fnStartNode());
        $oCur->oConsequent = [];
        $this->fnNext();
        if ($bIsCase) {
          $oCur->oTest = $this->fnParseExpression();
        } else {
          if ($bSawDefault) 
            $this->fnRaiseRecoverable($this->iLastTokStart, "Multiple default clauses");
          $bSawDefault = true;
          $oCur->oTest = null;
        }
        $this->fnExpect(TokenTypes::$aTypes['colon']);
      } else {
        if (!$oCur) 
          $this->fnUnexpected();
        array_push($oCur->oConsequent, $this->fnParseStatement(null));
      }
    }
    $this->fnExitScope();
    if ($oCur) 
      $this->fnFinishNode($oCur, "SwitchCase");
    $this->fnNext(); // Closing brace
    array_pop($this->aLabels);
    return $this->fnFinishNode($oNode, "SwitchStatement");
  }

  public function fnParseThrowStatement(&$oNode)
  {
    $this->fnNext();
    if (preg_match(Whitespace::lineBreak, mb_substr($this->sInput, $this->iLastTokEnd, $this->iStart)))
    //if (lineBreak.test($this->input.slice($this->iLastTokEnd, $this->iStart)))
      $this->fnRaise($this->iLastTokEnd, "Illegal newline after throw");
    $oNode->oArgument = $this->fnParseExpression();
    $this->fnSemicolon();
    return $this->fnFinishNode($oNode, "ThrowStatement");
  }

  // Reused empty array added for node fields that are always empty.

  public function fnParseTryStatement(&$oNode)
  {
    $this->fnNext();
    $oNode->oBlock = $this->parseBlock();
    $oNode->oHandler = null;
    if ($this->oType === TokenTypes::$aTypes['catch']) {
      $oClause = $this->fnStartNode();
      $this->fnNext();
      if ($this->fnEat(TokenTypes::$aTypes['parenL'])) {
        $oClause->oParam = $this->fnParseBindingAtom();
        $bSimple = $oClause->oParam->sType === "Identifier";
        $this->fnEnterScope($bSimple ? Scope::SCOPE_SIMPLE_CATCH : 0);
        $this->fnCheckLVal($oClause->oParam, $bSimple ? Scope::BIND_SIMPLE_CATCH : Scope::BIND_LEXICAL);
        $this->fnExpect(TokenTypes::$aTypes['parenR']);
      } else {
        if ($this->aOptions['ecmaVersion'] < 10) 
          $this->fnUnexpected();
        $oClause->oParam = null;
        $this->fnEnterScope(0);
      }
      $oClause->aBody = $this->fnParseBlock(false);
      $this->fnExitScope();
      $oNode->oHandler = $this->fnFinishNode($oClause, "CatchClause");
    }
    $oNode->oFinalizer = $this->fnEat(TokenTypes::$aTypes['finally']) ? $this->fnParseBlock() : null;
    if (!$oNode->oHandler && !$oNode->oFinalizer)
      $this->fnRaise($oNode->iStart, "Missing catch or finally clause");
    return $this->fnFinishNode(node, "TryStatement");
  }

  public function fnParseVarStatement(&$oNode, $sKind)
  {
    $this->fnNext();
    $this->fnParseVar($oNode, false, $sKind);
    $this->fnSemicolon();
    return $this->fnFinishNode($oNode, "VariableDeclaration");
  }

  public function fnParseWhileStatement(&$oNode)
  {
    $this->fnNext();
    $oNode->oTest = $this->fnParseParenExpression();
    array_push($this->aLabels, $this->oLoopLabel);
    $oNode->aBody = $this->fnParseStatement("while");
    array_pop($this->aLabels);
    return $this->fnFinishNode($oNode, "WhileStatement");
  }

  public function fnParseWithStatement(&$oNode)
  {
    if ($this->strict) 
      $this->Raise($this->iStart, "'with' in strict mode");
    $this->fnNext();
    $oNode->oObject = $this->fnParseParenExpression();
    $oNode->aBody = $this->fnParseStatement("with");
    return $this->fnFinishNode(node, "WithStatement");
  }

  public function fnParseEmptyStatement(&$oNode)
  {
    $this->fnNext();
    return $this->fnFinishNode($oNode, "EmptyStatement");
  }

  public function fnParseLabeledStatement(&$oNode, $sMaybeName, $oExpr, $sContext)
  {
    foreach ($this->aLabels as $oLabel)
      if ($oLabel->name === $sMaybeName)
        $this->fnRaise($oExpr->iStart, "Label '$sMaybeName' is already declared");
    
    $sKind = $this->oType->bIsLoop ? "loop" : $this->oType === TokenTypes::$aTypes['switch'] ? "switch" : null;
            
    for ($iI = count($this->aLabels) - 1; $iI >= 0; $iI--) {
      $oLabel = $this->aLabels[$iI];
      if ($oLabel->iStatementStart === $oNode->iStart) {
        // Update information about previous labels on this node
        $oLabel->iStatementStart = $this->iStart;
        $oLabel->sKind = $sKind;
      } else 
        break;
    }

    $oTempLabel = new Label();
    $oTempLabel->sName = $sMaybeName;
    $oTempLabel->iStatementStart = $this->iStart;
    array_push($this->aLabels, $oTempLabel);

    $oNode->aBody = $this->fnParseStatement($sContext);
    if ($oNode->aBody->sType === "ClassDeclaration" ||
        $oNode->aBody->sType === "VariableDeclaration" && $oNode->aBody->sKind !== "var" ||
        $oNode->aBody->sType === "FunctionDeclaration" && ($this->bStrict || $oNode->aBody->bGenerator || $oNode->aBody->bAsync))
      $this->fnRaiseRecoverable($oNode->aBody->iStart, "Invalid labeled declaration");
    array_pop($this->aLabels);
    $oNode->oLabel = $oExpr;
    return $this->fnFinishNode($oNode, "LabeledStatement");
  }

  public function fnParseExpressionStatement(&$oNode, $oExpr)
  {
    $oNode->oExpression = $oExpr;
    $this->fnSemicolon();
    return $this->fnFinishNode($oNode, "ExpressionStatement");
  }

  // Parse a semicolon-enclosed block of statements, handling `"use
  // strict"` declarations when `allowStrict` is true (used for
  // function bodies).

  public function fnParseBlock($bCreateNewLexicalScope = true, &$oNode = null) 
  {
    if (is_null($oNode))
      $oNode = $this->fnStartNode();
    $oNode->aBody = [];
    $this->fnExpect(TokenTypes::$aTypes['braceL']);
    if ($bCreateNewLexicalScope) 
      $this->fnEnterScope(0);
    while (!$this->fnEat(TokenTypes::$aTypes['braceR'])) {
      $oStmt = $this->fnParseStatement(null);
      array_push($oNode->aBody, $oStmt);
    }
    if ($bCreateNewLexicalScope) 
      $this->fnExitScope();
    return $this->fnFinishNode(node, "BlockStatement");
  }

  // Parse a regular `for` loop. The disambiguation code in
  // `parseStatement` will already have parsed the init statement or
  // expression.

  public function fnParseFor(&$oNode, $oInit)
  {
    $oNode->oInit = $oInit;
    $this->fnExpect(TokenTypes::$aTypes['semi']);
    $oNode->oTest = $this->oType === TokenTypes::$aTypes['semi'] ? null : $this->fnParseExpression();
    $this->fnExpect(TokenTypes::$aTypes['semi']);
    $oNode->oUpdate = $this->oType === TokenTypes::$aTypes['parenR'] ? null : $this->fnParseExpression();
    $this->fnExpect(TokenTypes::$aTypes['parenR']);
    $this->fnExitScope();
    $oNode->aBody = $this->fnParseStatement("for");
    array_pop($this->aLabels);
    return $this->fnFinishNode($oNode, "ForStatement");
  }

  // Parse a `for`/`in` and `for`/`of` loop, which are almost
  // same from parser's perspective.

  public function fnParseForIn(&$oNode, $oInit)
  {
    $sType = $this->oType === TokenTypes::$aTypes['in'] ? "ForInStatement" : "ForOfStatement";
    $this->fnNext();
    if ($sType === "ForInStatement") {
      if ($oInit->sType === "AssignmentPattern" ||
        ($oInit->sType === "VariableDeclaration" && $oInit->aDeclarations[0]->bInit != null &&
         ($this->bStrict || $oInit->aDeclarations[0]->oId->sType !== "Identifier")))
        $this->fnRaise($oInit->iStart, "Invalid assignment in for-in loop head");
    }
    $oNode->oLeft = $oInit;
    $oNode->oRight = $sType === "ForInStatement" ? $this->fnParseExpression() : $this->fnParseMaybeAssign();
    $this->fnExpect(TokenTypes::$aTypes['parenR']);
    $this->fnExitScope();
    $oNode->aBody = $this->fnParseStatement("for");
    array_pop($this->aLabels);
    return $this->fnFinishNode($oNode, $sType);
  }

  // Parse a list of variable declarations.

  public function fnParseVar(&$oNode, $bIsFor, $sKind)
  {
    $oNode->aDeclarations = [];
    $oNode->sKind = $sKind;
    for (;;) {
      $oDecl = $this->fnStartNode();
      $this->fnParseVarId($oDecl, $sKind);
      if ($this->fnEat(TokenTypes::$aTypes['eq'])) {
        $oDecl->oInit = $this->fnParseMaybeAssign($bIsFor);
      } else if ($sKind === "const" 
                  && !($this->oType === TokenTypes::$aTypes['in'] 
                       || ($this->aOptions['ecmaVersion'] >= 6 
                           && $this->fnIsContextual("of")))) {
        $this->fnUnexpected();
      } else if ($oDecl->oId->type !== "Identifier" 
                 && !($bIsFor 
                      && ($this->oType === TokenTypes::$aTypes['in'] 
                          || $this->fnIsContextual("of")))) {
        $this->fnRaise($this->iLastTokEnd, "Complex binding patterns require an initialization value");
      } else {
        $oDecl->oInit = null;
      }
      array_push($oNode->aDeclarations, $this->fnFinishNode($oDecl, "VariableDeclarator"));
      if (!$this->fnEat(TokenTypes::$aTypes['comma'])) 
        break;
    }
    return $oNode;
  }

  public function fnParseVarId(&$oDecl, $sKind)
  {
    $oDecl->oId = $this->fnParseBindingAtom($sKind);
    $this->fnCheckLVal($oDecl->oId, $sKind === "var" ? Scope::BIND_VAR : Scope::BIND_LEXICAL, false);
  }

  // Parse a function declaration or literal (depending on the
  // `isStatement` parameter).

  public function fnParseFunction(&$oNode, $iStatement, $bAllowExpressionBody, $bIsAsync)
  {
    $this->fnInitFunction($oNode);
    if ($this->aOptions['ecmaVersion'] >= 9 
        || $this->aOptions['ecmaVersion'] >= 6 
        && !$bIsAsync)
      $oNode->bGenerator = $this->fnEat(TokenTypes::$aTypes['star']);
    if ($this->aOptions['ecmaVersion'] >= 8)
      $oNode->bAsync = !!$bIsAsync;

    if ($iStatement & self::FUNC_STATEMENT) {
      $oNode->oId = ($iStatement & self::FUNC_NULLABLE_ID) && $this->oType !== TokenTypes::$aTypes['name'] ? null : $this->fnParseIdent();
      if ($oNode->oId && !($iStatement & self::FUNC_HANGING_STATEMENT))
        $this->fnCheckLVal($oNode->oId, $this->bInModule && !$this->fnInFunction() ? Scope::BIND_LEXICAL : Scope::BIND_FUNCTION);
    }

    $iOldYieldPos = $this->iYieldPos;
    $iOldAwaitPos = $this->iAwaitPos;
    $this->iYieldPos = 0;
    $this->iAwaitPos = 0;
    $this->fnEnterScope(Scope::fnFunctionFlags($oNode->bAsync, $oNode->bGenerator));

    if (!($iStatement & self::FUNC_STATEMENT))
      $oNode->oId = $this->oType === TokenTypes::$aTypes['name'] ? $this->fnParseIdent() : null;

    $this->fnParseFunctionParams($oNode);
    $this->fnParseFunctionBody($oNode, $bAllowExpressionBody);

    $this->iYieldPos = $iOldYieldPos;
    $this->iAwaitPos = $iOldAwaitPos;
    return $this->fnFinishNode($oNode, ($iStatement & self::FUNC_STATEMENT) ? "FunctionDeclaration" : "FunctionExpression");
  }

  public function fnParseFunctionParams(&$oNode)
  {
    $this->fnExpect(TokenTypes::$aTypes['parenL']);
    $oNode->oParams = $this->fnParseBindingList(TokenTypes::$aTypes['parenR'], false, $this->aOptions['ecmaVersion'] >= 8);
    $this->fnCheckYieldAwaitInDefaultParams();
  }

  // Parse a class declaration or literal (depending on the
  // `isStatement` parameter).

  public function fnParseClass(&$oNode, $bIsStatement)
  {
    $this->fnNext();
    $this->fnParseClassId($oNode, $bIsStatement);
    $this->fnParseClassSuper($oNode);
    $oClassBody = $this->fnStartNode();
    $bHadConstructor = false;
    $oClassBody->aBody = [];
    $this->fnExpect(TokenTypes::$aTypes['braceL']);
    
    while (!$this->fnEat(TokenTypes::$aTypes['braceR'])) {
      $oElement = $this->fnParseClassElement();
      if ($oElement) {
        array_push($oClassBody->aBody, $oElement);
        if ($oElement->sType === "MethodDefinition" && $oElement->sKind === "constructor") {
          if ($bHadConstructor) 
            $this->fnRaise($oElement->iStart, "Duplicate constructor in the same class");
          $bHadConstructor = true;
        }
      }
    }
    $oNode->aBody = $this->fnFinishNode($oClassBody, "ClassBody");
    return $this->fnFinishNode($oNode, $bIsStatement ? "ClassDeclaration" : "ClassExpression");
  }

  public function fnParseClassElement()
  {
    if ($this->fnEat(TokenTypes::$aTypes['semi'])) 
      return null;

    $oMethod = $this->fnStartNode();
    
    $fnTryContextual = function ($sK, $bNoLineBreak = false) use (&$oMethod)
    {
      $iStart = $this->iStart;
      $iStartLoc = $this->iStartLoc;
      
      if (!$this->fnEatContextual($sK)) 
        return false;
      if ($this->oType !== TokenTypes::$aTypes['parenL'] 
          && (!$bNoLineBreak 
              || !$this->fnCanInsertSemicolon())) 
        return true;
      
      if ($oMethod->oKey) 
        $this->fnUnexpected();
      
      $oMethod->bComputed = false;
      $oMethod->oKey = $this->fnStartNodeAt($iStart, $iStartLoc);
      $oMethod->oKey->sName = $sK;
      $this->fnFinishNode($oMethod->oKey, "Identifier");
      
      return false;
    };

    $oMethod->sKind = "method";
    $oMethod->bStatic = $fnTryContextual("static");
    $bIsGenerator = $this->fnEat(TokenTypes::$aTypes['star']);
    $bIsAsync = false;
    
    if (!$bIsGenerator) {
      if ($this->aOptions['ecmaVersion'] >= 8 && $fnTryContextual("async", true)) {
        $bIsAsync = true;
        $bIsGenerator = $this->aOptions['ecmaVersion'] >= 9 && $this->fnEat(TokenTypes::$aTypes['star']);
      } else if ($fnTryContextual("get")) {
        $oMethod->sKind = "get";
      } else if ($fnTryContextual("set")) {
        $oMethod->sKind = "set";
      }
    }
    
    if (!$oMethod->oKey) 
      $this->fnParsePropertyName($oMethod);
    
    $oKey = $oMethod.oKey;
    if (!$oMethod->bComputed 
        && !$oMethod->bStatic 
        && ($oKey->sType === "Identifier" 
            && $oKey->sName === "constructor" 
            || $oKey->sType === "Literal" 
            && $oKey->sValue === "constructor")) {
      if ($oMethod->sKind !== "method") 
        $this->fnRaise($oKey->iStart, "Constructor can't have get/set modifier");
      if ($bIsGenerator) 
        $this->fnRaise($oKey->iStart, "Constructor can't be a generator");
      if ($bIsAsync) 
        $this->fnRaise($oKey->iStart, "Constructor can't be an async method");
      $oMethod->sKind = "constructor";      
    } else if ($oMethod->bStatic 
               && $oKey->sType === "Identifier" 
               && $oKey->sName === "prototype") {
      $this->fnRaise($oKey->iStart, "Classes may not have a static property named prototype");
    }
    $this->fnParseClassMethod($oMethod, $bIsGenerator, $bIsAsync);
    if ($oMethod->sKind === "get" && count($oMethod->oValue->aParams) !== 0)
      $this->fnRaiseRecoverable($oMethod->oValue->iStart, "getter should have no params");
    if ($oMethod->sKind === "set" && count($oMethod->oValue->aParams) !== 1)
      $this->fnRaiseRecoverable(method.value.start, "setter should have exactly one param");
    if ($oMethod->sKind === "set" && $oMethod->oValue->aParams[0]->sType === "RestElement")
      $this->fnRaiseRecoverable($oMethod->oValue->aParams[0].iStart, "Setter cannot use rest params");
    
    return $oMethod;
  }

  public function fnParseClassMethod(&$oMethod, $bIsGenerator, $bIsAsync)
  {
    $oMethod->oValue = $this->fnParseMethod($bIsGenerator, $bIsAsync);
    return $this->fnFinishNode($oMethod, "MethodDefinition");
  }

  public function fnParseClassId(&$oNode, $bIsStatement)
  {
    $oNode->oId = $this->oType === TokenTypes::$aTypes['name'] ? 
      $this->fnParseIdent() : 
      $bIsStatement === true ? 
        $this->fnUnexpected() : 
        null;
  }

  public function fnParseClassSuper(&$oNode)
  {
    $oNode->bSuperClass = $this->fnEat(TokenTypes::$aTypes['extends']) ? 
      $this->fnParseExprSubscripts() : 
      null;
  }

  // Parses module export declaration.

  public function fnParseExport(&$oNode, &$aExports)
  {
    $this->fnNext();
    // export * from '...'
    if ($this->fnEat(TokenTypes::$aTypes['star'])) {
      $this->fnExpectContextual("from");
      if ($this->oType !== TokenTypes::$aTypes['string']) 
        $this->fnUnexpected();
      $oNode->oSource = $this->fnParseExprAtom();
      $this->fnSemicolon();
      return $this->fnFinishNode($oNode, "ExportAllDeclaration");
    }
    if ($this->fnEat(TokenTypes::$aTypes['default'])) { // export default ...
      $this->fnCheckExport(exports, "default", $this->lastTokStart);
      $bIsAsync;
      if ($this->oType === TokenTypes::$aTypes['function'] 
          || ($bIsAsync = $this->fnIsAsyncFunction())) {
        $oFNode = $this->fnStartNode();
        $this->fnNext();
        if ($bIsAsync) 
          $this->fnNext();
        $oNode->oDeclaration = $this->fnParseFunction($oFNode, self::FUNC_STATEMENT | self::FUNC_NULLABLE_ID, false, $bIsAsync, true);
      } else if ($this->oType === TokenTypes::$aTypes['class']) {
        $oCNode = $this->fnStartNode();
        $oNode->oDeclaration = $this->fnParseClass($oCNode, "nullableID");
      } else {
        $oNode->oDeclaration = $this->fnParseMaybeAssign();
        $this->fnSemicolon();
      }
      return $this->fnFinishNode($oNode, "ExportDefaultDeclaration");
    }
    // export var|const|let|function|class ...
    if ($this->shouldParseExportStatement()) {
      $oNode->oDeclaration = $this->fnParseStatement(null);
      if ($oNode->oDeclaration->sType === "VariableDeclaration")
        $this->fnCheckVariableExport($aExports, $oNode->oDeclaration->aDeclarations);
      else
        $this->fnCheckExport($aExports, $oNode->oDeclaration->oId->sName, $oNode->oDeclaration->oId->iStart);
      $oNode->oSpecifiers = [];
      $oNode->oSource = null;
    } else { // export { x, y as z } [from '...']
      $oNode->oDeclaration = null;
      $oNode->oSpecifiers = $this->fnParseExportSpecifiers($aExports);
      if ($this->fnEatContextual("from")) {
        if ($this->oType !== TokenTypes::$aTypes['string']) 
          $this->fnUnexpected();
        $oNode->oSource = $this->fnParseExprAtom();
      } else {
        // check for keywords used as local names
        //for (let spec of node.specifiers) {
        foreach ($oNode->oSpecifiers as $oSpec) {
          $this->fnCheckUnreserved($oSpec->local);
        }

        $oNode->oSource = null;
      }
      $this->fnSemicolon();
    }
    return $this->fnFinishNode($oNode, "ExportNamedDeclaration");
  }

  public function fnCheckExport(&$aExports, $sName, $iPos)
  {
    if (!$aExports) 
      return;
    if (isset($aExports[$sName]))
      $this->fnRaiseRecoverable($iPos, "Duplicate export '$sName'");
    $aExports[$sName] = true;
  }

  public function fnCheckPatternExport(&$aExports, $oPat)
  {
    $sType = $oPat->sType;
    if ($sType === "Identifier")
      $this->fnCheckExport($aExports, $oPat->sName, $oPat->iStart);
    else if ($sType === "ObjectPattern")
      foreach ($oPat->aProperties as $oProp) {
        $this->fnCheckPatternExport($aExports, $oProp);
      }
    else if ($sType === "ArrayPattern")
      foreach ($oPat->aElements as $oElt) {
        if ($oElt) 
          $this->fnCheckPatternExport($aExports, $oElt);
      }
    else if ($sType === "Property")
      $this->fnCheckPatternExport($aExports, $oPat->oValue);
    else if ($sType === "AssignmentPattern")
      $this->fnCheckPatternExport($aExports, $oPat->oLeft);
    else if ($sType === "RestElement")
      $this->fnCheckPatternExport($aExports, $oPat->oArgument);
    else if ($sType === "ParenthesizedExpression")
      $this->fnCheckPatternExport($aExports, $oPat->oExpression);
  }

  public function fnCheckVariableExport(&$aExports, $aDecls)
  {
    if (!$aExports) 
      return;
    foreach ($aDecls as $oDecl)
      $this->fnCheckPatternExport($aExports, $oDecl->oId);
  }

  public function fnShouldParseExportStatement()
  {
    return $this->oType->sKeyword === "var" ||
      $this->oType->sKeyword === "const" ||
      $this->oType->sKeyword === "class" ||
      $this->oType->sKeyword === "function" ||
      $this->fnIsLet() ||
      $this->fnIsAsyncFunction();
  }

  // Parses a comma-separated list of module exports.

  public function fnParseExportSpecifiers(&$aExports)
  {
    $aNodes = [];
    $bFirst = true;
    // export { x, y as z } [from '...']
    $this->fnExpect(TokenTypes::$aTypes['braceL']);
    while (!$this->fnEat(TokenTypes::$aTypes['braceR'])) {
      if (!$bFirst) {
        $this->fnExpect(TokenTypes::$aTypes['comma']);
        if ($this->fnAfterTrailingComma(TokenTypes::$aTypes['braceR'])) 
          break;
      } else 
        $bFirst = false;

      $oNode = $this->fnStartNode();
      $oNode->oLocal = $this->fnParseIdent(true);
      $oNode->oExported = $this->fnEatContextual("as") ? $this->fnParseIdent(true) : $oNode->oLocal;
      $this->fnCheckExport($aExports, $oNode->oExported->sName, $oNode->oExported->iStart);
      array_push($aNodes, $this->fnFinishNode($oNode, "ExportSpecifier"));
    }
    return $aNodes;
  }

  // Parses import declaration.

  public function fnParseImport(&$oNode)
  {
    $this->fnNext();
    // import '...'
    if ($this->oType === TokenTypes::$aTypes['string']) {
      $oNode->oSpecifiers = [];
      $oNode->oSource = $this->fnParseExprAtom();
    } else {
      $oNode->oSpecifiers = $this->fnParseImportSpecifiers();
      $this->fnExpectContextual("from");
      $oNode->oSource = $this->oType === TokenTypes::$aTypes['string'] ? $this->fnParseExprAtom() : $this->fnUnexpected();
    }
    $this->fnSemicolon();
    return $this->fnFinishNode(node, "ImportDeclaration");
  }

  // Parses a comma-separated list of module imports.

  public function fnParseImportSpecifiers()
  {
    $aNodes = [];
    $bFirst = true;
    if ($this->oType === TokenTypes::$aTypes['name']) {
      // import defaultObj, { x, y as z } from '...'
      $oNode = $this->fnStartNode();
      $oNode->oLocal = $this->fnParseIdent();
      $this->fnCheckLVal($oNode->oLocal, Scope::BIND_LEXICAL);
      array_push($aNodes, $this->fnFinishNode($oNode, "ImportDefaultSpecifier"));
      if (!$this->fnEat(TokenTypes::$aTypes['comma'])) 
        return $aNodes;
    }
    if ($this->oType === TokenTypes::$aTypes['star']) {
      $oNode = $this->fnStartNode();
      $this->fnNext();
      $this->fnExpectContextual("as");
      $oNode->oLocal = $this->fnParseIdent();
      $this->fnCheckLVal($oNode->oLocal, Scope::BIND_LEXICAL);
      array_push($aNodes, $this->fnFinishNode($oNode, "ImportNamespaceSpecifier"));
      return $aNodes;
    }
    $this->fnExpect(TokenTypes::$aTypes['braceL']);
    while (!$this->fnEat(TokenTypes::$aTypes['braceR'])) {
      if (!$bFirst) {
        $this->fnExpect(TokenTypes::$aTypes['comma']);
        if ($this->fnAfterTrailingComma(TokenTypes::$aTypes['braceR'])) 
          break;
      } else 
        $bFirst = false;

      $oNode = $this->fnStartNode();
      $oNode->oImported = $this->fnParseIdent(true);
      if ($this->fnEatContextual("as")) {
        $oNode->oLocal = $this->fnParseIdent();
      } else {
        $this->fnCheckUnreserved(node.imported);
        $oNode->oLocal = $oNode->oImported;
      }
      $this->fnCheckLVal($oNode->oLocal, Scope::BIND_LEXICAL);
      array_push($aNodes, $this->fnFinishNode($oNode, "ImportSpecifier"));
    }
    return $aNodes;
  }

  // Set `ExpressionStatement#directive` property for directive prologues.
  public function fnAdaptDirectivePrologue(&$aStatements)
  {
    for ($iI = 0; $iI < count($aStatements) && $this->fnIsDirectiveCandidate($aStatements[$iI]); ++$iI) {
      $aStatements[$iI]->sDirective = mb_substr($aStatements[$iI]->oExpression->sRaw, 1, count($aStatements[$iI]->oExpression->sRaw)-2);
    }
  }
  public function fnIsDirectiveCandidate($oStatement)
  {
    return (
      $oStatement->sType === "ExpressionStatement" &&
      $oStatement->oExpression->sType === "Literal" &&
      is_string($oStatement->oExpression->mValue) &&
      // Reject parenthesized strings.
      (Utilities::fnGetCharAt($this->sInput, $oStatement->iStart) === "\"" 
       || Utilities::fnGetCharAt($this->sInput, $oStatement->iStart) === "'")
    );
  }  
  
  public function fnInitialContext() 
  {
    return [TokenContextTypes::$aTypes['b_stat']];
  }

  public function fnBraceIsBlock($oPrevType) 
  {
    $oParent = $this->fnCurContext();
    if ($oParent === TokenContextTypes::$aTypes['f_expr'] 
        || $oParent === TokenContextTypes::$aTypes['f_stat'])
      return true;
    if ($oPrevType === TokenTypes::$aTypes['colon']
        && ($oParent === TokenContextTypes::$aTypes['b_stat'] 
            || $oParent === TokenContextTypes::$aTypes['b_expr']))
      return !$oParent->bIsExpr;

    // The check for `TokenTypes::$aTypes['name'] && exprAllowed` detects whether we are
    // after a `yield` or `of` construct. See the `updateContext` for
    // `TokenTypes::$aTypes['name']`.
    if ($oPrevType === TokenTypes::$aTypes['return'] 
        || $oPrevType === TokenTypes::$aTypes['name']
        && $this->bExprAllowed)
      return preg_match(Whitespace::lineBreak, mb_substr($this->sInput, $this->iLastTokEnd, $this->iStart));
    if ($oPrevType === TokenTypes::$aTypes['else']
        || $oPrevType === TokenTypes::$aTypes['semi'] 
        || $oPrevType === TokenTypes::$aTypes['eof'] 
        || $oPrevType === TokenTypes::$aTypes['parenR'] 
        || $oPrevType === TokenTypes::$aTypes['arrow'])
      return true;
    if ($oPrevType === TokenTypes::$aTypes['braceL'])
      return $oParent === TokenContextTypes::$aTypes['b_stat'];
    if ($oPrevType === TokenTypes::$aTypes['var']
        || $oPrevType === TokenTypes::$aTypes['name'])
      return false;
    return !$this->bExprAllowed;
  }

  public function fnInGeneratorContext() {
    for ($iI = count($this->aContext) - 1; $iI >= 1; $iI--) {
      $oContext = $this->aContext[$iI];
      if ($oContext->sToken === "function")
        return $oContext->bGenerator;
    }
    return false;
  }

  public function fnUpdateContext($oPrevType) {
    $fnUpdate;
    $oType = $this->oType;
    if ($oType->sKeyword && $oPrevType === TokenTypes::$aTypes['dot'])
      $this->bExprAllowed = false;
    else if ($fnUpdate = $oType->fnUpdateContext) {
      $fnUpdate = Closure::bind($fnUpdate, $this);
      $fnUpdate($oPrevType);
    } else
      $this->bExprAllowed = $oType->bBeforeExpr;
  }

  public function fnEnterScope($iFlags) 
  {
    array_push($this->aScopeStack, new Scope($iFlags));
  }
  
  public function fnExitScope() 
  {
    array_pop($this->aScopeStack);
  }

  public function fnDeclareName($sName, $iBindingType, $iPos) 
  {
    $bRedeclared = false;
    
    if ($iBindingType === BIND_LEXICAL) {
      $oScope = $this->fnCurrentScope();
      $bRedeclared = in_array($sName, $oScope->aLexical) || in_array($sName, $oScope->aVar);
      array_push($oScope->aLexical, $sName);
    } else if ($iBindingType === BIND_SIMPLE_CATCH) {
      $oScope = $this->fnCurrentScope();
      array_push($oScope->aLexical, $sName);
    } else if ($iBindingType === BIND_FUNCTION) {
      $oScope = $this->fnCurrentScope();
      $bRedeclared = in_array($sName, $oScope->aLexical);
      array_push($oScope->aVar, $sName);
    } else {
      for ($iI = count($this->aScopeStack) - 1; $iI >= 0; --$iI) {
        $oScope = $this->aScopeStack[$iI];
        if (in_array($sName, $oScope->aLexical) 
              && !($oScope->iFlags & Scope::SCOPE_SIMPLE_CATCH) 
              && $oScope->aLexical[0] === $sName) 
          $bRedeclared = true;
        array_push($oScope->aVar, $sName);
        if ($oScope->iFlags & Scope::SCOPE_VAR) 
          break;
      }
    }
    
    if ($bRedeclared) 
      $this->fnRaiseRecoverable(
        $iPos, 
        "Identifier '$sName' has already been declared"
      );
  }

  public function fnCurrentScope()
  {
    return $this->aScopeStack[count($this->aScopeStack) - 1];
  }

  public function fnCurrentVarScope()
  {
    for ($iI = count($this->aScopeStack) - 1;; $iI--) {
      $oScope = $this->aScopeStack[$iI];
      if ($oScope->iFlags & Scope::SCOPE_VAR) 
        return $oScope;
    }
  }

  public function fnInNonArrowFunction()
  {
    for ($iI = count($this->aScopeStack) - 1; $iI >= 0; $iI--)
      if ($this->aScopeStack[$iI]->iFlags 
          & Scope::SCOPE_FUNCTION 
          && !($this->aScopeStack[$iI]->iFlags & Scope::SCOPE_ARROW))
        return true;
    return false;
  }  
  
  public function fnStartNode() 
  {
    return new Node($this, $this->iStart, $this->oStartLoc);
  }
  
  public function fnStartNodeAt($iPos, $oLoc) 
  {
    return new Node($this, $iPos, $oLoc);
  }

  // Finish an AST node, adding `type` and `end` properties.

  public function _fnFinishNodeAt(&$oNode, $sType, $iPos, $oLoc) 
  {
    $oNode->sType = $sType;
    $oNode->iEnd = $iPos;
    if ($this->aOptions['locations'])
      $oNode->oLoc->oEnd = $oLoc;
    if ($this->aOptions['ranges'])
      $oNode->aRange[1] = $iPos;
    return $oNode;
  }

  public function fnFinishNode(&$oNode, $sType) 
  {
    return $this->_fnFinishNodeAt($oNode, $sType, $this->iLastTokEnd, $this->oLastTokEndLoc);
  }

  // Finish node at given position

  public function fnFinishNodeAt(&$oNode, $sType, $iPos, $oLoc) 
  {
    return $this->_fnFinishNodeAt($oNode, $sType, $iPos, $oLoc);
  }
  
  public function fnStrictDirective($iStart) 
  {
    for (;;) {
      $aMatches = [];
      preg_match(
        Whitespace::skipWhiteSpace, 
        $this->sInput,
        $aMatches,
        0,
        $iStart
      );
      $iStart += mb_strlen(@$aMatches[0]);
      
      $aMatches = [];
      $sLiteral = "/^(?:'((?:\\.|[^'])*?)'|\"((?:\\.|[^\"])*?)\"|;)/";
      preg_match(
        $sLiteral,
        mb_substr($this->sInput, $iStart),
        $aMatches
      );
      
      if (empty($aMatches)) 
        return false;
      
      if (@$aMatches[1] == "use strict" || @$aMatches[2] == "use strict") 
        return true;
      
      $iStart += mb_strlen($aMatches[0]);
    }
  }
  
  public function fnEat($oType) 
  {
    if ($this->oType === $oType) {
      $this->fnNext();
      return true;
    } else {
      return false;
    }
  }

  // Tests whether parsed token is a contextual keyword.

  public function fnIsContextual($sName) 
  {
    return $this->oType === TokenTypes::$aTypes['name']
      && $this->mValue === $sName 
      && !$this->bContainsEsc;
  }

  // Consumes contextual keyword if possible.

  public function fnEatContextual($sName) 
  {
    if (!$this->fnIsContextual($sName)) 
      return false;
    $this->fnNext();
    return true;
  }

  // Asserts that following token is given contextual keyword.

  public function fnExpectContextual($sName) 
  {
    if (!$this->fnEatContextual($sName)) 
      $this->fnUnexpected();
  }

  // Test whether a semicolon can be inserted at the current position.

  public function fnCanInsertSemicolon() 
  {
    return $this->oType === TokenTypes::$aTypes['eof'] ||
      $this->oType === TokenTypes::$aTypes['braceR'] ||
      preg_match(Whitespace::lineBreak, mb_substr($this->sInput, $this->iLastTokEnd, $this->iStart - $this->iLastTokEnd));
  }

  public function fnInsertSemicolon()
  {
    if ($this->fnCanInsertSemicolon()) {
      if (is_callable($this->aOptions['onInsertedSemicolon']))
        $this->aOptions['onInsertedSemicolon']($this->iLastTokEnd, $this->oLastTokEndLoc);
      return true;
    }
  }

  // Consume a semicolon, or, failing that, see if we are allowed to
  // pretend that there is a semicolon at this position.

  public function fnSemicolon() 
  {
    if (!$this->fnEat(TokenTypes::$aTypes['semi']) 
        && !$this->fnInsertSemicolon()) 
      $this->fnUnexpected();
  }

  public function fnAfterTrailingComma($oTokType, $bNotNext) 
  {
    if ($this->oType === $oTokType) {
      if (is_callable($this->aOptions['onTrailingComma']))
        $this->aOptions['onTrailingComma']($this->iLastTokStart, $this->oLastTokStartLoc);
      if (!$bNotNext)
        $this->fnNext();
      return true;
    }
  }

  // Expect a token of a given type. If found, consume it, otherwise,
  // raise an unexpected token error.

  public function fnExpect($oType) 
  {
    if (!$this->fnEat($oType)) 
      $this->fnUnexpected();
  }

  // Raise an unexpected token error.

  public function fnUnexpected($iPos) 
  {
    $this->fnRaise($iPos != null ? $iPos : $this->iStart, "Unexpected token");
  }

  public function fnCheckPatternErrors($oRefDestructuringErrors, $bIsAssign) 
  {
    if (!$oRefDestructuringErrors) 
      return;
    if ($oRefDestructuringErrors->iTrailingComma > -1)
      $this->fnRaiseRecoverable($oRefDestructuringErrors->iTrailingComma, "Comma is not permitted after the rest element");
    $iParens = $bIsAssign ? $oRefDestructuringErrors->iParenthesizedAssign : $oRefDestructuringErrors->iParenthesizedBind;
    if ($iParens > -1) 
      $this->fnRaiseRecoverable($iParens, "Parenthesized pattern");
  }

  public function fnCheckExpressionErrors($oRefDestructuringErrors, $bAndThrow) 
  {
    if (!$oRefDestructuringErrors) 
      return false;
    $iShorthandAssign = $oRefDestructuringErrors->iShorthandAssign;
    $iDoubleProto = $oRefDestructuringErrors->iDoubleProto;
    if (!$bAndThrow) 
      return $iShorthandAssign >= 0 || $iDoubleProto >= 0;
    if ($iShorthandAssign >= 0)
      $this->fnRaise($iShorthandAssign, "Shorthand property assignments are valid only in destructuring patterns");
    if ($iDoubleProto >= 0)
      $this->fnRaiseRecoverable($iDoubleProto, "Redefinition of __proto__ property");
  }

  public function fnCheckYieldAwaitInDefaultParams() 
  {
    if ($this->iYieldPos 
        && (!$this->iAwaitPos 
            || $this->iYieldPos < $this->iawaitPos))
      $this->fnRaise($this->iYieldPos, "Yield expression cannot be a default value");
    if ($this->iAwaitPos)
      $this->fnRaise($this->iAwaitPos, "Await expression cannot be a default value");
  }

  public function fnIsSimpleAssignTarget($oExpr) 
  {
    if ($oExpr->sType === "ParenthesizedExpression")
      return $this->fnIsSimpleAssignTarget($oExpr->oExpression);
    return $oExpr->sType === "Identifier" 
      || $oExpr->sType === "MemberExpression";
  }
  
  public function fnNext() 
  {
    if (is_callable($this->aOptions['onToken']))
      $this->aOptions['onToken'](new Token($this));

    $this->iLastTokEnd = $this->iEnd;
    $this->iLastTokStart = $this->iStart;
    $this->oLastTokEndLoc = $this->oEndLoc;
    $this->oLastTokStartLoc = $this->oStartLoc;
    $this->fnNextToken();
  }

  public function fnGetToken() 
  {
    $this->fnNext();
    return new Token($this);
  }

  /*
  // If we're in an ES6 environment, make parsers iterable
  if (typeof Symbol !== "undefined")
    pp[Symbol.iterator] = function() {
      return {
        next: () => {
          let token = $this->getToken()
          return {
            done: token.type === TokenTypes::$aTypes['eof'],
            value: token
          }
        }
      }
    }
  */
  
  // Toggle strict mode. Re-reads the next number or string to please
  // pedantic tests (`"use strict"; 010;` should fail).

  public function fnCurContext() 
  {
    return $this->aContext[count($this->aContext) - 1];
  }

  // Read a single token, updating the parser object's token-related
  // properties.

  public function fnNextToken() 
  {
    $aCurContext = $this->fnCurContext();
    if (!$aCurContext || !$aCurContext->bPreserveSpace)
      $this->fnSkipSpace();

    $this->iStart = $this->iPos;
    if ($this->aOptions['locations']) 
      $this->oStartLoc = $this->fnCurPosition();
    if ($this->iPos >= mb_strlen($this->sInput)) 
      return $this->fnFinishToken(TokenTypes::$aTypes['eof']);

    if (is_callable($aCurContext->fnOverride)) 
      return $aCurContext->fnOverride($this);
    else 
      $this->fnReadToken($this->fnFullCharCodeAtPos());
  }

  public function fnReadToken($iCode) 
  {
    // Identifier or keyword. '\uXXXX' sequences are allowed in
    // identifiers, so '\' also dispatches to that.
    if (Identifier::fnIsIdentifierStart($iCode, $this->aOptions['ecmaVersion'] >= 6) 
        || $iCode === 92 /* '\' */)
      return $this->fnReadWord();

    return $this->fnGetTokenFromCode($iCode);
  }

  public function fnFullCharCodeAtPos() 
  {
    $iCode = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos);
    if ($iCode <= 0xd7ff || $iCode >= 0xe000) 
      return $iCode;
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    return ($iCode << 10) + $iNext - 0x35fdc00;
  }

  public function fnSkipBlockComment() 
  {
    $oStartLoc = null;
    
    if (is_callable($this->aOptions['onComment']))
      $oStartLoc = $this->fnCurPosition();
    
    $iStart = $this->iPos;
    $iEnd = mb_strpos($this->sInput, "*/", $this->iPos += 2);
    if ($iEnd === false) 
      $this->fnRaise($this->iPos - 2, "Unterminated comment");
    $this->iPos = $iEnd + 2;
    
    if ($this->aOptions['locations']) {
      if (preg_match_all(Whitespace::lineBreak, $this->sInput, $aMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($aMatches[0] as $aMatch) {
          ++$this->iCurLine;
          $this->iLineStart = $aMatch[1] + mb_strlen($aMatch[0]);
        }
      }
    }
    if (is_callable($this->aOptions['onComment']))
      $this->aOptions['onComment'](
        true, 
        mb_substr($this->sInput, $iStart + 2, $iEnd - $iStart - 2), 
        $iStart, 
        $this->iPos,
        $oStartLoc, 
        $this->fnCurPosition()
      );
  }

  public function fnSkipLineComment($iStartSkip) 
  {
    $iStart = $this->iPos;
    $oStartLoc = null;
    
    if (is_callable($this->aOptions['onComment']))
      $oStartLoc = $this->curPosition();
    
    $iCh = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos += $iStartSkip);
    
    while ($this->iPos < mb_strlen($this->sInput) && !Whitespace::fnIsNewLine($iCh)) {
      $iCh = Utilities::fnGetCharCodeAt($this->sInput, ++$this->iPos);
    }

    if (is_callable($this->aOptions['onComment']))
      $this->aOptions['onComment'](
        false, 
        mb_substr($this->sInput, $iStart + $iStartSkip, $this->iPos - $iStart - $iStartSkip),
        $iStart, 
        $this->iPos,
        $oStartLoc, 
        $this->fnCurPosition()
      );
  }

  // Called at the start of the parse and after every token. Skips
  // whitespace and comments, and.

  public function fnSkipSpace() 
  {
    while ($this->iPos < mb_strlen($this->sInput)) {
      $iCh = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos);
      switch ($iCh) {
        case 32: case 160: // ' '
          ++$this->iPos;
          break;
        case 13:
          if (Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1) === 10) {
            ++$this->iPos;
          }
        case 10: case 8232: case 8233:
          ++$this->iPos;
          if ($this->aOptions['locations']) {
            ++$this->curLine;
            $this->iLineStart = $this->iPos;
          }
          break;
        case 47: // '/'
          switch (Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1)) {
            case 42: // '*'
              $this->fnSkipBlockComment();
              break;
            case 47:
              $this->fnSkipLineComment(2);
              break;
            default:
              break 3;
          }
          break;
        default:
          if ($iCh > 8 && $iCh < 14 
              || $iCh >= 5760 
              && preg_match(Whitespace::nonASCIIwhitespace, Utilities::fnUnichr($iCh))) {
            ++$this->iPos;
          } else {
            break 2;
          }
      }
    }
  }

  // Called at the end of every token. Sets `end`, `val`, and
  // maintains `context` and `exprAllowed`, and skips the space after
  // the token, so that the next one's `start` will point at the
  // right position.

  public function fnFinishToken($oType, $mVal) 
  {
    $this->iEnd = $this->iPos;
    if ($this->aOptions['locations']) 
      $this->oEndLoc = $this->fnCurPosition();
    $oPrevType = $this->oType;
    $this->oType = $oType;
    $this->mValue = $mVal;

    $this->fnUpdateContext($oPrevType);
  }

  // ### Token reading

  // This is the function that is called to fetch the next token. It
  // is somewhat obscure, because it works in character codes rather
  // than characters, and because operator parsing has been inlined
  // into it.
  //
  // All in the name of speed.
  //
  public function fnReadToken_dot() 
  {
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    if ($iNext >= 48 && $iNext <= 57) 
      return $this->fnReadNumber(true);
    
    $iNext2 = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 2);
    if ($this->aOptions['ecmaVersion'] >= 6 && $iNext === 46 && $iNext2 === 46) { // 46 = dot '.'
      $this->iPos += 3;
      return $this->fnFinishToken(TokenTypes::$aTypes['ellipsis']);
    } else {
      ++$this->iPos;
      return $this->fnFinishToken(TokenTypes::$aTypes['dot']);
    }
  }

  public function fnReadToken_slash() 
  { // '/'
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    if ($this->bExprAllowed) { 
      ++$this->iPos; 
      return $this->fnReadRegexp();
    }
    if ($iNext === 61) 
      return $this->fnFinishOp(TokenTypes::$aTypes['assign'], 2);
    return $this->fnFinishOp(TokenTypes::$aTypes['slash'], 1);
  }

  public function fnReadToken_mult_modulo_exp($iCode) 
  { // '%*'
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    $iSize = 1;
    $oTokentype = $iCode === 42 ? 
      TokenTypes::$aTypes['star'] : 
      TokenTypes::$aTypes['modulo'];

    // exponentiation operator ** and **=
    if ($this->aOptions['ecmaVersion'] >= 7 && $iCode === 42 && $iNext === 42) {
      ++$iSize;
      $oTokentype = TokenTypes::$aTypes['starstar'];
      $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 2);
    }

    if ($iNext === 61) 
      return $this->fnFinishOp(TokenTypes::$aTypes['assign'], $iSize + 1);
            
    return $this->fnFinishOp($oTokentype, $iSize);
  }

  public function fnReadToken_pipe_amp($iCode) 
  { // '|&'
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    if ($iNext === $iCode) 
      return $this->fnFinishOp(
        $iCode === 124 ? 
          TokenTypes::$aTypes['logicalOR'] : 
          TokenTypes::$aTypes['logicalAND'], 
        2
      );
    if ($iNext === 61) 
      return $this->fnFinishOp(TokenTypes::$aTypes['assign'], 2);
    return $this->fnFinishOp(
      $iCode === 124 ? 
        TokenTypes::$aTypes['bitwiseOR'] : 
        TokenTypes::$aTypes['bitwiseAND'],
      1
    );
  }

  public function fnReadToken_caret() 
  { // '^'
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    if ($iNext === 61) 
      return $this->fnFinishOp(TokenTypes::$aTypes['assign'], 2);
    return $this->fnFinishOp(TokenTypes::$aTypes['bitwiseXOR'], 1);
  }

  public function fnReadToken_plus_min($iCode) 
  { // '+-'
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    if ($iNext === $iCode) {
      if ($iNext === 45 
          && !$this->bInModule 
          && Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 2) === 62 
          && (
            $this->iLastTokEnd === 0 
            || preg_match(
                Whitespace::lineBreak, 
                mb_substr(
                  $this->sInput, 
                  $this->iLastTokEnd,
                  $this->iPos - $this->iLastTokEnd
                )
              )
          )) {
        // A `-->` line comment
        $this->fnSkipLineComment(3);
        $this->fnSkipSpace();
        return $this->fnNextToken();
      }
      return $this->fnFinishOp(TokenTypes::$aTypes['incDec'], 2);
    }
    if ($iNext === 61) 
      return $this->fnFinishOp(TokenTypes::$aTypes['assign'], 2);
    return $this->fnFinishOp(TokenTypes::$aTypes['plusMin'], 1);
  }

  public function fnReadToken_lt_gt($iCode) 
  { // '<>'
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    $iSize = 1;
    if ($iNext === $iCode) {
      $iSize = $iCode === 62 
        && Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 2) === 62 ? 
          3 : 
          2;
      if (Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + $iSize) === 61) 
        return $this->fnFinishOp(TokenTypes::$aTypes['assign'], $iSize + 1);
      return $this->fnFinishOp(TokenTypes::$aTypes['bitShift'], $iSize);
    }
    if ($iNext === 33 
        && $iCode === 60 
        && !$this->bInModule 
        && Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 2) === 45 
        && Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 3) === 45) {
      // `<!--`, an XML-style comment that should be interpreted as a line comment
      $this->fnSkipLineComment(4);
      $this->fnSkipSpace();
      return $this->fnNextToken();
    }
    if ($iNext === 61) 
      $iSize = 2;
    return $this->fnFinishOp(TokenTypes::$aTypes['relational'], $iSize);
  }

  public function fnReadToken_eq_excl($iCode) 
  { // '=!'
    $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
    if ($iNext === 61) 
      return $this->fnFinishOp(
        TokenTypes::$aTypes['equality'], 
        Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 2) === 61 ? 
          3 : 
          2
      );
    if ($iCode === 61 && next === 62 && $this->aOptions['ecmaVersion'] >= 6) { // '=>'
      $this->iPos += 2;
      return $this->fnFinishToken(TokenTypes::$aTypes['arrow']);
    }
    return $this->fnFinishOp($iCode === 61 ? TokenTypes::$aTypes['eq'] : TokenTypes::$aTypes['prefix'], 1);
  }

  public function getTokenFromCode($iCode) 
  {
    switch ($iCode) {
      // The interpretation of a dot depends on whether it is followed
      // by a digit or another two dots.
      case 46: // '.'
        return $this->fnReadToken_dot();

      // Punctuation tokens.
      case 40: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['parenL']);
      case 41: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['parenR']);
      case 59: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['semi']);
      case 44: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['comma']);
      case 91: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['bracketL']);
      case 93: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['bracketR']);
      case 123: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['braceL']);
      case 125: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['braceR']);
      case 58: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['colon']);
      case 63: ++$this->iPos; return $this->fnFinishToken(TokenTypes::$aTypes['question']);

      case 96: // '`'
        if ($this->aOptions['ecmaVersion'] < 6) 
          break;
        ++$this->iPos;
        return $this->fnFinishToken(TokenTypes::$aTypes['backQuote']);

      case 48: // '0'
        $iNext = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos + 1);
        if ($iNext === 120 || $iNext === 88) 
          return $this->fnReadRadixNumber(16); // '0x', '0X' - hex number
        if ($this->aOptions.ecmaVersion >= 6) {
          if (next === 111 || next === 79) 
            return $this->fnReadRadixNumber(8); // '0o', '0O' - octal number
          if (next === 98 || next === 66) 
            return $this->fnReadRadixNumber(2); // '0b', '0B' - binary number
        }

      // Anything else beginning with a digit is an integer, octal
      // number, or float.
      case 49: case 50: case 51: case 52: case 53: case 54: case 55: case 56: case 57: // 1-9
        return $this->fnReadNumber(false);

      // Quotes produce strings.
      case 34: case 39: // '"', "'"
        return $this->fnReadString($iCode);

      // Operators are parsed inline in tiny state machines. '=' (61) is
      // often referred to. `finishOp` simply skips the amount of
      // characters it is given as second argument, and returns a token
      // of the type given by its first argument.

      case 47: // '/'
        return $this->fnReadToken_slash();

      case 37: case 42: // '%*'
        return $this->fnReadToken_mult_modulo_exp($iCode);

      case 124: case 38: // '|&'
        return $this->fnReadToken_pipe_amp($iCode);

      case 94: // '^'
        return $this->fnReadToken_caret();

      case 43: case 45: // '+-'
        return $this->fnReadToken_plus_min($iCode);

      case 60: case 62: // '<>'
        return $this->fnReadToken_lt_gt($iCode);

      case 61: case 33: // '=!'
        return $this->fnReadToken_eq_excl($iCode);

      case 126: // '~'
        return $this->fnFinishOp(TokenTypes::$aTypes['prefix'], 1);
    }

    $this->fnRaise($this->iPos, "Unexpected character '" . $this->fnCodePointToString($iCode) . "'");
  }

  public function fnCodePointToString($iCode) 
  {
    // UTF-16 Decoding
    if ($iCode <= 0xFFFF) 
      return Utilities::fnUnichr($iCode);
    $iCode -= 0x10000;
    return Utilities::fnUnichr(($iCode >> 10) + 0xD800, ($iCode & 1023) + 0xDC00);
  }
  
  public function fnFinishOp($oType, $iSize) 
  {
    $sStr = mb_substr($this->sInput, $this->iPos, $iSize);
    $this->iPos += $iSize;
    return $this->fnFinishToken($oType, $sStr);
  }

  public function fnReadRegexp() 
  {
    $bEscaped = false;
    $bInClass = false;
    $iStart = $this->iPos;
    
    for (;;) {
      if ($this->iPos >= mb_strlen($this->sInput)) 
        $this->fnRaise($iStart, "Unterminated regular expression");
      
      $sCh = Utilities::fnGetCharAt($this->sInput, $this->iPos);
      if (preg_match(Whitespace::lineBreak, $sCh)) 
        $this->fnRaise($iStart, "Unterminated regular expression");
              
      if (!$bEscaped) {
        if ($sCh === "[") 
          $bInClass = true;
        else if ($sCh === "]" && $bInClass) 
          $bInClass = false;
        else if ($sCh === "/" && !$bInClass) 
          break;
        $bEscaped = $sCh === "\\";
      } else 
        $bEscaped = false;
      ++$this->iPos;
    }
    
    $sPattern = mb_substr($this->sInput, $iStart, $this->iPos - $iStart);
    ++$this->iPos;
    $iFlagsStart = $this->iPos;
    $iFlags = $this->fnReadWord1();
    
    if ($this->bContainsEsc) 
      $this->fnUnexpected($iFlagsStart);

    // Validate pattern
    $oState = $this->oRegexpState ? 
      $this->oRegexpState : 
      $this->oRegexpState = new RegExpValidationState($this);
    $oState->fnReset($iStart, $sPattern, $iFlags);
    $this->fnValidateRegExpFlags($oState);
    $this->fnValidateRegExpPattern($oState);

    // Create Literal#value property value.
    $oValue = null;
    /*
    try {
      value = new RegExp(pattern, flags)
    } catch (e) {
      // ESTree requires null if it failed to instantiate RegExp object.
      // https://github.com/estree/estree/blob/a27003adf4fd7bfad44de9cef372a2eacd527b1c/es5.md#regexpliteral
    }
    */
    return $this->fnFinishToken(TokenTypes::$aTypes['regexp'], [$sPattern, $iFlags, $oValue]);
  }

  // Read an integer in the given radix. Return null if zero digits
  // were read, the integer value otherwise. When `len` is given, this
  // will return `null` unless the integer has exactly `len` digits.

  public function fnReadInt($iRadix, $iLen) 
  {
    $iStart = $this->iPos;
    $iTotal = 0;
    for ($iI = 0, $iE = $iLen == null ? INF : $iLen; $iI < $iE; ++$iI) {
      $iCode = Utilities::fnGetCharAt($this->sInput, $this->iPos);
      $iVal;
      if ($iCode >= 97) 
        $iVal = $iCode - 97 + 10; // a
      else if ($iCode >= 65) 
        $iVal = $iCode - 65 + 10; // A
      else if ($iCode >= 48 && $iCode <= 57) 
        $iVal = $iCode - 48; // 0-9
      else 
        $iVal = INF;
      if ($iVal >= $iRadix) 
        break;
      ++$this->iPos;
      $iTotal = $iTotal * $iRadix + $iVal;
    }
    if ($this->iPos === $iStart || $iLen != null && $this->iPos - $iStart !== $iLen) 
      return null;

    return $iTotal;
  }

  public function fnReadRadixNumber($iRadix) 
  {
    $this->iPos += 2; // 0x
    $iVal = $this->fnReadInt($iRadix);
    
    if (is_null($iVal)) 
      $this->fnRaise($this->iStart + 2, "Expected number in radix " . $iRadix);
    if (Identifier::fnIsIdentifierStart($this->fullCharCodeAtPos())) 
      $this->fnRaise($this->iPos, "Identifier directly after number");
    
    return $this->fnFinishToken(TokenTypes::$aTypes['num'], $iVal);
  }

  // Read an integer, octal integer, or floating-point number.

  public function fnReadNumber($bStartsWithDot) 
  {
    $iStart = $this->iPos;
    if (!$bStartsWithDot && $this->fnReadInt(10) === null) 
      $this->fnRaise($iStart, "Invalid number");
    $bOctal = ($this->iPos - $iStart) >= 2
      && Utilities::fnGetCharAt($this->sInput, $iStart) === 48;
    if ($bOctal && $this->bStrict) 
      $this->fnRaise($iStart, "Invalid number");
    if ($bOctal 
        && preg_match(
          "/[89]/", 
          mb_substr(
            $this->sInput, 
            $iStart, 
            $this->iPos - $iStart
          )
        )
       ) 
      $bOctal = false;
    $iNext = Utilities::fnGetCharAt($this->sInput, $this->iPos);
    if ($iNext === 46 && !$bOctal) { // '.'
      ++$this->iPos;
      $this->fnReadInt(10);
      $iNext = Utilities::fnGetCharAt($this->sInput, $this->iPos);
    }
    if (($iNext === 69 || $iNext === 101) && !$bOctal) { // 'eE'
      $iNext = Utilities::fnGetCharAt($this->sInput, ++$this->iPos);
      if ($iNext === 43 || $iNext === 45) 
        ++$this->iPos; // '+-'
      if ($this->fnReadInt(10) === null) 
        $this->fnRaise($iStart, "Invalid number");
    }
    if (Identifier::fnIsIdentifierStart($this->fnFullCharCodeAtPos())) 
      $this->fnRaise($this->iPos, "Identifier directly after number");

    $sStr = mb_substr($this->sInput, $iStart, $this->iPos - $iStart);
    $iVal = $bOctal ? intval($sStr, 8) : floatval($sStr);
    return $this->fnFinishToken(TokenTypes::$aTypes['num'], $iVal);
  }

  // Read a string value, interpreting backslash-escapes.

  public function fnReadCodePoint() 
  {
    $iCh = Utilities::fnGetCharAt($this->sInput, $this->iPos);
    $iCode;

    if ($iCh === 123) { // '{'
      if ($this->aOptions['ecmaVersion'] < 6) 
        $this->fnUnexpected();
      $iCodePos = ++$this->iPos;
      $iCode = $this->fnReadHexChar(mb_strpos($this->sInput, "}", $this->iPos) - $this->iPos);
      ++$this->iPos;
      if ($iCode > 0x10FFFF) 
        $this->fnInvalidStringToken($iCodePos, "Code point out of bounds");
    } else {
      $iCode = $this->fnReadHexChar(4);
    }
    return $iCode;
  }

  public function fnReadString($iQuote) 
  {
    $sOut = "";
    $iChunkStart = ++$this->iPos;
    
    for (;;) {
      if ($this->iPos >= mb_strlen($this->sInput))
        $this->fnRaise($this->iStart, "Unterminated string constant");
      $iCh = Utilities::fnGetCharAt($this->sInput, $this->iPos);
      if ($iCh === $iQuote) 
        break;
      if ($iCh === 92) { // '\'
        $sOut .= mb_substr($this->sInput, $iChunkStart, $this->iPos - $iChunkStart);
        $sOut .= $this->fnReadEscapedChar(false);
        $iChunkStart = $this->iPos;
      } else {
        if (Whitespace::fnIsNewLine($iCh, $this->aOptions['ecmaVersion'] >= 10)) 
          $this->fnRaise($this->iStart, "Unterminated string constant");
        ++$this->iPos;
      }
    }
    
    $sOut .= mb_substr($this->sInput, $iChunkStart, $this->iPos++ - $iChunkStart);
    
    return $this->fnFinishToken(TokenTypes::$aTypes['string'], $sOut);
  }

  // Reads template string tokens.

  public function fnTryReadTemplateToken() 
  {
    $this->bInTemplateElement = true;
    
    try {
      $this->fnReadTmplToken();
    } catch (InvalidTemplateEscapeError $oException) {
      $this->fnReadInvalidTemplateToken();
    }

    $this->bInTemplateElement = false;
  }

  public function fnInvalidStringToken($iPosition, $sMessage) 
  {
    if ($this->bInTemplateElement && $this->aOptions['ecmaVersion'] >= 9) {
      throw new InvalidTemplateEscapeError();
    } else {
      $this->fnRaise($iPosition, $sMessage);
    }
  }

  public function fnReadTmplToken() 
  {
    $sOut = "";
    $iChunkStart = $this->iPos;
    
    for (;;) {
      if ($this->iPos >= mb_strlen($this->sInput)) 
        $this->fnRaise($this->iStart, "Unterminated template");
      
      $iCh = Utilities::fnGetCharAt($this->sInput, $this->iPos);
      
      if ($iCh === 96 || $iCh === 36 && Utilities::fnGetCharAt($this->sInput, $this->iPos + 1) === 123) { // '`', '${'
        if ($this->iPos === $this->iStart 
            && ($this->oType === TokenTypes::$aTypes['template'] 
                || $this->oType === TokenTypes::$aTypes['invalidTemplate'])
           ) {
          if ($iCh === 36) {
            $this->iPos += 2;
            return $this->fnFinishToken(TokenTypes::$aTypes['dollarBraceL']);
          } else {
            ++$this->iPos;
            return $this->fnFinishToken(TokenTypes::$aTypes['backQuote']);
          }
        }
        $sOut .= mb_substr($this->sInput, $iChunkStart, $this->iPos - $iChunkStart);
        return $this->fnFinishToken(TokenTypes::$aTypes['template'], $sOut);
      }
      if ($iCh === 92) { // '\'
        $sOut .= mb_substr($this->sInput, $iChunkStart, $this->iPos - $iChunkStart);
        $sOut .= $this->fnReadEscapedChar(true);
        $iChunkStart = $this->iPos;
      } else if (Whitespace::fnIsNewLine(ch)) {
        $sOut .= mb_substr($this->sInput, $iChunkStart, $this->iPos);
        ++$this->iPos;
        switch ($iCh) {
          case 13:
            if (Utilities::fnGetCharAt($this->sInput, $this->iPos) === 10) 
              ++$this->iPos;
          case 10:
            $sOut .= "\n";
            break;
          default:
            $sOut .= Utilities::fnUnichr($iCh);
            break;
        }
        if ($this->aOptions['locations']) {
          ++$this->curLine;
          $this->iLineStart = $this->iPos;
        }
        $iChunkStart = $this->iPos;
      } else {
        ++$this->iPos;
      }
    }
  }

  // Reads a template token to search for the end, without validating any escape sequences
  public function fnReadInvalidTemplateToken() 
  {
    for (; $this->iPos < mb_strlen($this->sInput); $this->iPos++) {
      switch (Utilities::fnGetCharAt($this->sInput, $this->iPos)) {
        case "\\":
          ++$this->iPos;
          break;

        case "$":
          if (Utilities::fnGetCharAt($this->sInput, $this->iPos + 1) !== "{") {
            break;
          }
        // falls through

        case "`":
          return $this->fnFinishToken(
            TokenTypes::$aTypes['invalidTemplate'], 
            mb_substr($this->sInput, $this->iStart, $this->iPos - $this->iStart)
          );

        // no default
      }
    }
    $this->fnRaise($this->iStart, "Unterminated template");
  }

  // Used to read escaped characters

  public function fnReadEscapedChar($bInTemplate) 
  {
    $iCh = Utilities::fnGetCharAt($this->sInput, ++$this->iPos);
    
    ++$this->iPos;
    
    switch ($iCh) {
      case 110: return "\n"; // 'n' -> '\n'
      case 114: return "\r"; // 'r' -> '\r'
      case 120: return Utilities::fnUnichr($this->fnReadHexChar(2)); // 'x'
      case 117: return $this->fnCodePointToString($this->fnReadCodePoint()); // 'u'
      case 116: return "\t"; // 't' -> '\t'
      case 98: return "\b"; // 'b' -> '\b'
      case 118: return "\x{000b}"; // 'v' -> '\u000b'
      case 102: return "\f"; // 'f' -> '\f'
      case 13: 
        if (Utilities::fnGetCharAt($this->sInput, $this->iPos) === 10) 
          ++$this->iPos; // '\r\n'
      case 10: // ' \n'
        if ($this->aOptions['locations']) { 
          $this->iLineStart = $this->iPos;
          ++$this->iCurLine;
        }
        return "";
      default:
        if ($iCh >= 48 && $iCh <= 55) {
          preg_match("/^[0-7]+/", mb_substr($this->sInput, $this->iPos - 1, 3), $aMatches);
          $sOctalStr = $aMatches[0];
          $iOctal = intval($sOctalStr, 8);
          if ($iOctal > 255) {
            $sOctalStr = mb_substr($sOctalStr, 0, mb_strlen($sOctalStr)-1);
            $iOctal = intval($sOctalStr, 8);
          }
          $this->iPos += mb_strlen($sOctalStr) - 1;
          $iCh = Utilities::fnGetCharAt($this->sInput, $this->iPos);
          if (($sOctalStr !== "0" || $iCh === 56 || $iCh === 57) && ($this->bStrict || $bInTemplate)) {
            $this->fnInvalidStringToken(
              $this->iPos - 1 - mb_strlen($sOctalStr),
              $bInTemplate
                ? "Octal literal in template string"
                : "Octal literal in strict mode"
            );
          }
          return Utilities::fnUnichr($iOctal);
        }
        return Utilities::fnUnichr($iCh);
    }
  }

  // Used to read character escape sequences ('\x', '\u', '\U').

  public function fnReadHexChar($iLen) 
  {
    $iCodePos = $this->iPos;
    $iN = $this->fnReadInt(16, $iLen);
    if (is_null($iN)) 
      $this->fnInvalidStringToken($iCodePos, "Bad character escape sequence");
    return $iN;
  }

  // Read an identifier, and return it as a string. Sets `$this->containsEsc`
  // to whether the word contained a '\u' escape.
  //
  // Incrementally adds only escaped chars, adding other chunks as-is
  // as a micro-optimization.

  public function fnReadWord1() 
  {
    $this->bContainsEsc = false;
    $sWord = "";
    $bFirst = true;
    $iChunkStart = $this->iPos;
    $bAstral = $this->aOptions['ecmaVersion'] >= 6;
    
    while ($this->iPos < mb_strlen($this->sInput)) {
      $iCh = $this->fnFullCharCodeAtPos();
      if (Identifier::fnIsIdentifierChar($iCh, $bAstral)) {
        $this->iPos += $iCh <= 0xffff ? 1 : 2;
      } else if ($iCh === 92) { // "\"
        $this->bContainsEsc = true;
        $sWord .= mb_substr($this->sInput, $iChunkStart, $this->iPos - $iChunkStart);
        $iEscStart = $this->iPos;
        
        if (Utilities::fnGetCharAt($this->sInput, ++$this->iPos) !== 117) // "u"
          $this->fnInvalidStringToken($this->iPos, "Expecting Unicode escape sequence \\uXXXX");
        
        ++$this->iPos;
        $sEsc = $this->fnReadCodePoint();
        $bFlag = false;
        
        if ($bFirst)
          $bFlag = Identifier::isIdentifierStart($sEsc, $bAstral);
        else
          $bFlag = Identifier::isIdentifierChar($sEsc, $bAstral);
        
        if (!$bFlag)
          $this->fnInvalidStringToken($iEscStart, "Invalid Unicode escape");
        
        $sWord .= $this->fnCodePointToString($sEsc);
        $iChunkStart = $this->iPos;
      } else {
        break;
      }
      $bFirst = false;
    }
    return $sWord . mb_substr($this->sInput, $iChunkStart, $this->iPos - $iChunkStart);
  }

  // Read an identifier or keyword token. Will check for reserved
  // words when necessary.

  public function fnReadWord() 
  {
    $sWord = $this->fnReadWord1();
    $oType = TokenTypes::$aTypes['name'];
    if (preg_match($this->sKeywords, $sWord)) {
      if ($this->bContainsEsc) 
        $this->fnRaiseRecoverable($this->iStart, "Escape sequence in keyword " . $sWord);
      $oType = TokenTypes::$aKeywords[$sWord];
    }
    return $this->fnFinishToken($oType, $sWord);
  }
  
  public function fnToAssignable(&$oNode, $bIsBinding, $oRefDestructuringErrors) 
  {
    if ($this->aOptions['ecmaVersion'] >= 6 && $oNode) {
      switch ($oNode->sType) {
      case "Identifier":
        if ($this->fnInAsync() && $oNode->sName === "await")
          $this->fnRaise($oNode->iStart, "Can not use 'await' as identifier inside an async function");
        break;

      case "ObjectPattern":
      case "ArrayPattern":
      case "RestElement":
        break;

      case "ObjectExpression":
        $oNode->sType = "ObjectPattern";
        if ($oRefDestructuringErrors) 
          $this->fnCheckPatternErrors($oRefDestructuringErrors, true);
        foreach ($oNode->aProperties as $oProp) {
          $this->fnToAssignable($oProp, $bIsBinding);
          // Early error:
          //   AssignmentRestProperty[Yield, Await] :
          //     `...` DestructuringAssignmentTarget[Yield, Await]
          //
          //   It is a Syntax Error if |DestructuringAssignmentTarget| is an |ArrayLiteral| or an |ObjectLiteral|.
          if (
            $oProp->sType === "RestElement" 
              && ($oProp->oArgument->sType === "ArrayPattern" 
                  || $oProp->oArgument->sType === "ObjectPattern")
          ) {
            $this->fnRaise($oProp->oArgument->iStart, "Unexpected token");
          }
        }
        break;

      case "Property":
        // AssignmentProperty has type === "Property"
        if ($oNode->sKind !== "init") 
          $this->fnRaise($oNode->oKey->iStart, "Object pattern can't contain getter or setter");
        $this->fnToAssignable($oNode->oValue, $bIsBinding);
        break;

      case "ArrayExpression":
        $oNode->sType = "ArrayPattern";
        if ($oRefDestructuringErrors) 
          $this->fnCheckPatternErrors($oRefDestructuringErrors, true);
        $this->fnToAssignableList($oNode->aElements, $bIsBinding);
        break;

      case "SpreadElement":
        $oNode->sType = "RestElement";
        $this->fnToAssignable($oNode->oArgument, $bIsBinding);
        if ($oNode->oArgument->sType === "AssignmentPattern")
          $this->fnRaise($oNode->oArgument->iStart, "Rest elements cannot have a default value");
        break;

      case "AssignmentExpression":
        if ($oNode->sOperator !== "=") 
          $this->fnRaise($oNode->oLeft->iEnd, "Only '=' operator can be used for specifying default value.");
        $oNode->sType = "AssignmentPattern";
        //delete node.operator;
        $this->fnToAssignable($oNode->oLeft, $bIsBinding);
        // falls through to AssignmentPattern

      case "AssignmentPattern":
        break;

      case "ParenthesizedExpression":
        $this->fnToAssignable($oNode->oExpression, $bIsBinding);
        break;

      case "MemberExpression":
        if (!$bIsBinding) 
          break;

      default:
        $this->fnRaise($oNode->iStart, "Assigning to rvalue");
      }
    } else if ($oRefDestructuringErrors) 
      $this->fnCheckPatternErrors($oRefDestructuringErrors, true);
    return $oNode;
  }

  // Convert list of expression atoms to binding list.

  public function toAssignableList($aExprList, $bIsBinding) 
  {
    $iEnd = count($aExprList);
    for ($iI = 0; $iI < $iEnd; $iI++) {
      $oElt = $aExprList[$iI];
      if ($oElt) 
        $this->fnToAssignable($oElt, $bIsBinding);
    }
    if ($iEnd) {
      $oLast = $aExprList[$iEnd - 1];
      if ($this->aOptions['ecmaVersion'] === 6 
          && $bIsBinding 
          && $oLast 
          && $oLast->sType === "RestElement" 
          && $oLast->oArgument->sType !== "Identifier")
        $this->fnUnexpected($oLast->oArgument->iStart);
    }
    return $aExprList;
  }

  // Parses spread element.

  public function fnParseSpread($oRefDestructuringErrors) 
  {
    $oNode = $this->fnStartNode();
    $this->fnNext();
    $oNode->oArgument = $this->fnParseMaybeAssign(false, $oRefDestructuringErrors);
    return $this->fnFinishNode($oNode, "SpreadElement");
  }

  public function fnParseRestBinding() 
  {
    $oNode = $this->fnStartNode();
    $this->fnNext();

    // RestElement inside of a function parameter must be an identifier
    if ($this->aOptions['ecmaVersion'] === 6 
        && $this->oType !== TokenTypes::$aTypes['name']) 
      $this->fnUnexpected();

    $oNode->oArgument = $this->fnParseBindingAtom();

    return $this->fnFinishNode($oNode, "RestElement");
  }

  // Parses lvalue (assignable) atom.

  public function fnParseBindingAtom() 
  {
    if ($this->aOptions['ecmaVersion'] >= 6) {
      switch ($this->oType) {
        case TokenTypes::$aTypes['bracketL']:
          $oNode = $this->fnStartNode();
          $this->fnNext();
          $oNode->aElements = $this->fnParseBindingList(TokenTypes::$aTypes['bracketR'], true, true);
          return $this->fnFinishNode($oNode, "ArrayPattern");

        case TokenTypes::$aTypes['braceL']:
          return $this->fnParseObj(true);
      }
    }
    return $this->fnParseIdent();
  }

  public function fnParseBindingList($oClose, $bAllowEmpty, $bAllowTrailingComma) 
  {
    $aElts = [];
    $bFirst = true;
    while (!$this->fnEat($oClose)) {
      if ($bFirst) 
        $bFirst = false;
      else 
        $this->fnExpect(TokenTypes::$aTypes['comma']);
      if ($bAllowEmpty && $this->oType === TokenTypes::$aTypes['comma']) {
        array_push($aElts, null);
      } else if ($bAllowTrailingComma && $this->fnAfterTrailingComma($oClose)) {
        break;
      } else if ($this->oType === TokenTypes::$aTypes['ellipsis']) {
        $oRest = $this->fnParseRestBinding();
        $this->fnParseBindingListItem($oRest);
        array_push($aElts, $oRest);
        if ($this->oType === TokenTypes::$aTypes['comma']) 
          $this->fnRaise($this->iStart, "Comma is not permitted after the rest element");
        $this->fnExpect($oClose);
        break;
      } else {
        $oElem = $this->fnParseMaybeDefault($this->iStart, $this->oStartLoc);
        $this->fnParseBindingListItem($oElem);
        array_push($aElts, $oElem);
      }
    }
    return $aElts;
  }

  public function parseBindingListItem($oParam) 
  {
    return $oParam;
  }

  // Parses assignment pattern around given atom if possible.

  public function fnParseMaybeDefault($iStartPos, $oStartLoc, $oLeft) 
  {
    if (!$oLeft) {
      $oLeft = $this->fnParseBindingAtom();
    }
    if ($this->aOptions['ecmaVersion'] < 6 
        || !$this->fnEat(TokenTypes::$aTypes['eq'])) 
      return $oLeft;
    $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
    $oNode->oLeft = $oLeft;
    $oNode->oRight = $this->fnParseMaybeAssign();
    return $this->fnFinishNode($oNode, "AssignmentPattern");
  }

  // Verify that a node is an lval — something that can be assigned
  // to.
  // bindingType can be either:
  // 'var' indicating that the lval creates a 'var' binding
  // 'let' indicating that the lval creates a lexical ('let' or 'const') binding
  // 'none' indicating that the binding should be checked for illegal identifiers, but not for duplicate references

  public function fnCheckLVal($oExpr, $iBindingType = Scope::BIND_NONE, $aCheckClashes) 
  {
    switch ($oExpr->sType) {
      case "Identifier":
        if ($this->bStrict && preg_match($this->sReservedWordsStrictBind, $oExpr->sName))
          $this->fnRaiseRecoverable($oExpr->iStart, ($iBindingType ? "Binding " : "Assigning to ") . $oExpr->sName . " in strict mode");
        if ($aCheckClashes) {
          if (in_array($oExpr->sName, $aCheckClashes))
            $this->fnRaiseRecoverable($oExpr->iStart, "Argument name clash");
          $aCheckClashes[$oExpr->sName] = true;
        }
        if ($iBindingType !== Scope::BIND_NONE && $iBindingType !== Scope::BIND_OUTSIDE) 
          $this->fnDeclareName($oExpr->sName, $iBindingType, $oExpr->iStart);
        break;

      case "MemberExpression":
        if ($iBindingType) 
          $this->fnRaiseRecoverable($oExpr->iStart, "Binding member expression");
        break;

      case "ObjectPattern":
        foreach ($oExpr->aProperties as $oProp)
          $this->fnCheckLVal($oProp, $iBindingType, $aCheckClashes);
        break;

      case "Property":
        // AssignmentProperty has type === "Property"
        $this->fnCheckLVal($oExpr->oValue, $iBindingType, $aCheckClashes);
        break;

      case "ArrayPattern":
        foreach ($oExpr->aElements as $oElem) {
          if ($oElem) 
            $this->fnCheckLVal($oElem, $iBindingType, $aCheckClashes);
        }
        break;

      case "AssignmentPattern":
        $this->fnCheckLVal($oExpr->oLeft, $iBindingType, $aCheckClashes);
        break;

      case "RestElement":
        $this->fnCheckLVal($oExpr->oArgument, $iBindingType, $aCheckClashes);
        break;

      case "ParenthesizedExpression":
        $this->fnCheckLVal($oExpr->oExpression, $iBindingType, $aCheckClashes);
        break;

      default:
        $this->fnRaise($oExpr->iStart, ($iBindingType ? "Binding" : "Assigning to") . " rvalue");
    }
  }
  
  public function fnCheckPropClash($oProp, &$aPropHash, &$oRefDestructuringErrors) 
  {
    if ($this->aOptions['ecmaVersion'] >= 9 && $oProp->sType === "SpreadElement")
      return;
    if ($this->aOptions['ecmaVersion'] >= 6 && ($oProp->bComputed || $oProp->bMethod || $oProp->bShorthand))
      return;
    $oKey = $oProp->oKey;
    $sName;
    switch (key.type) {
      case "Identifier": 
        $sName = $oKey->sName; 
        break;
      case "Literal": 
        $sName = $oKey->sValue; //?
        break;
      default: 
        return;
    }
    $sKind = $oProp->sKind;
    if ($this->aOptions['ecmaVersion'] >= 6) {
      if ($sName === "__proto__" && $sKind === "init") {
        if ($aPropHash['proto']) {
          if ($oRefDestructuringErrors && $oRefDestructuringErrors->iDoubleProto < 0) 
            $oRefDestructuringErrors->iDoubleProto = $oKey->iStart;
          // Backwards-compat kludge. Can be removed in version 6.0
          else 
            $this->fnRaiseRecoverable($oKey->iStart, "Redefinition of __proto__ property");
        }
        $aPropHash['proto'] = true;
      }
      return;
    }
    $sName = "$" . $sName;
    $oOther = &$aPropHash[$sName];
    if ($oOther) {
      $bRedefinition;
      if ($sKind === "init") {
        $bRedefinition = $this->bStrict && $oOther['init'] || $oOther['get'] || $oOther['set'];
      } else {
        $bRedefinition = $oOther['init'] || $oOther[$sKind];
      }
      if ($bRedefinition)
        $this->fnRaiseRecoverable($oKey->iStart, "Redefinition of property");
    } else {
      //$oOther = $aPropHash[$sName] = [
      $oOther = [
        'init' => false,
        'get' => false,
        'set' => false,
      ];
    }
    $oOther[$sKind] = true;
  }

  // ### Expression parsing

  // These nest, from the most general expression type at the top to
  // 'atomic', nondivisible expression types at the bottom. Most of
  // the functions will simply let the function(s) below them parse,
  // and, *if* the syntactic construct they handle is present, wrap
  // the AST node that the inner parser gave them in another node.

  // Parse a full expression. The optional arguments are used to
  // forbid the `in` operator (in for loops initalization expressions)
  // and provide reference for storing '=' operator inside shorthand
  // property assignment in contexts where both object expression
  // and object pattern might appear (so it's possible to raise
  // delayed syntax error at correct position).

  public function fnParseExpression($oNoIn, $oRefDestructuringErrors) 
  {
    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    $oExpr = $this->fnParseMaybeAssign($oNoIn, $oRefDestructuringErrors);
    if ($this->oType === TokenTypes::$aTypes['comma']) {
      $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
      $oNode->aExpressions = [$oExpr];
      while ($this->fnEat(TokenTypes::$aTypes['comma'])) 
        array_push($oNode->aExpressions, $this->fnParseMaybeAssign($oNoIn, $oRefDestructuringErrors));
      return $this->fnFinishNode($oNode, "SequenceExpression");
    }
    return $oExpr;
  }

  // Parse an assignment expression. This includes applications of
  // operators like `+=`.

  public function fnParseMaybeAssign($oNoIn, &$oRefDestructuringErrors, $fnAfterLeftParse) 
  {
    if ($this->fnIsContextual("yield")) {
      if ($this->fnInGenerator()) 
        return $this->fnParseYield();
      // The tokenizer will assume an expression is allowed after
      // `yield`, but this isn't that kind of yield
      else 
        $this->bExprAllowed = false;
    }

    $bOwnDestructuringErrors = false;
    $iOldParenAssign = -1;
    $iOldTrailingComma = -1;
    if ($oRefDestructuringErrors) {
      $iOldParenAssign = $oRefDestructuringErrors->iParenthesizedAssign;
      $iOldTrailingComma = $oRefDestructuringErrors->iTrailingComma;
      $oRefDestructuringErrors->iParenthesizedAssign = $oRefDestructuringErrors->iTrailingComma = -1;
    } else {
      $oRefDestructuringErrors = new DestructuringErrors();
      $bOwnDestructuringErrors = true;
    }

    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    
    if ($this->oType === TokenTypes::$aTypes['parenL'] 
        || $this->oType === TokenTypes::$aTypes['name'])
      $this->iPotentialArrowAt = $this->iStart;
    $oLeft = $this->fnParseMaybeConditional($oNoIn, $oRefDestructuringErrors);
    if (is_callable($fnAfterLeftParse)) {
      $fnTemp = Closure::bind($fnAfterLeftParse, $this);
      $oLeft = $fnTemp($oLeft, $iStartPos, $oStartLoc);
    }
    if ($this->oType->bIsAssign) {
      $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
      $oNode->sOperator = $this->sValue;
      $oNode->oLeft = $this->oType === TokenTypes::$aTypes['eq'] ? 
        $this->fnToAssignable($oLeft, false, $oRefDestructuringErrors) : 
        $oLeft;
      //if (!$bOwnDestructuringErrors) 
      //  DestructuringErrors.call($oRefDestructuringErrors)
      $oRefDestructuringErrors->iShorthandAssign = -1; // reset because shorthand default was used correctly
      $this->fnCheckLVal($oLeft);
      $this->fnNext();
      $oNode->oRight = $this->fnParseMaybeAssign($oNoIn);
      return $this->fnFinishNode($oNode, "AssignmentExpression");
    } else {
      if ($bOwnDestructuringErrors) 
        $this->fnCheckExpressionErrors($oRefDestructuringErrors, true);
    }
    if ($iOldParenAssign > -1) 
      $oRefDestructuringErrors->iParenthesizedAssign = $iOldParenAssign;
    if ($iOldTrailingComma > -1) 
      $oRefDestructuringErrors->iTrailingComma = $iOldTrailingComma;
    return $oLeft;
  }

  // Parse a ternary conditional (`?:`) operator.

  public function fnParseMaybeConditional($oNoIn, $oRefDestructuringErrors) 
  {
    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    $oExpr = $this->fnParseExprOps($oNoIn, $oRefDestructuringErrors);
    if ($this->fnCheckExpressionErrors($oRefDestructuringErrors)) 
      return $oExpr;
    if ($this->fnEat(TokenTypes::$aTypes['question'])) {
      $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
      $oNode->oTest = $oExpr;
      $oNode->oConsequent = $this->fnParseMaybeAssign();
      $this->fnExpect(TokenTypes::$aTypes['colon']);
      $oNode->oAlternate = $this->fnParseMaybeAssign(noIn);
      return $this->fnFinishNode($oNode, "ConditionalExpression");
    }
    return $oExpr;
  }

  // Start the precedence parser.

  public function fnParseExprOps($oNoIn, $oRefDestructuringErrors) 
  {
    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    $oExpr = $this->fnParseMaybeUnary($oRefDestructuringErrors, false);
    if ($this->fnCheckExpressionErrors($oRefDestructuringErrors)) 
      return $oExpr;
    return $oExpr->iStart === $iStartPos 
      && $oExpr->sType === "ArrowFunctionExpression" ? 
        $oExpr : 
        $this->fnParseExprOp($oExpr, $iStartPos, $oStartLoc, -1, $oNoIn);
  }

  // Parse binary operators with the operator precedence parsing
  // algorithm. `left` is the left-hand side of the operator.
  // `minPrec` provides context that allows the function to stop and
  // defer further parser to one of its callers when it encounters an
  // operator that has a lower precedence than the set it is parsing.

  public function fnParseExprOp($oLeft, $iLeftStartPos, $oLeftStartLoc, $iMinPrec, $oNoIn) 
  {
    $iPrec = $this->oType->iBinop;
    if ($iPrec != null && (!$oNoIn || $this->oType !== TokenTypes::$aTypes['_in'])) {
      if ($iPrec > $iMinPrec) {
        $bLogical = $this->oType === TokenTypes::$aTypes['logicalOR'] 
          || $this->oType === TokenTypes::$aTypes['logicalAND'];
        $sOp = $this->mValue;
        $this->fnNext();
        $iStartPos = $this->iStart;
        $oStartLoc = $this->oStartLoc;
        $oRight = $this->fnParseExprOp($this->fnParseMaybeUnary(null, false), $iStartPos, $oStartLoc, $iPrec, $oNoIn);
        $oNode = $this->fnBuildBinary($iLeftStartPos, $oLeftStartLoc, $oLeft, $oRight, $sOp, $bLogical);
        return $this->fnParseExprOp($oNode, $iLeftStartPos, $oLeftStartLoc, $iMinPrec, $oNoIn);
      }
    }
    return $oLeft;
  }

  public function fnBuildBinary($iStartPos, $oStartLoc, $oLeft, $oRight, $sOp, $bLogical) 
  {
    $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
    $oNode->oLeft = $oLeft;
    $oNode->sOperator = $sOp;
    $oNode->oRight = $oRight;
    return $this->fnFinishNode($oNode, $bLogical ? "LogicalExpression" : "BinaryExpression");
  }

  // Parse unary operators, both prefix and postfix.

  public function fnParseMaybeUnary($oRefDestructuringErrors, $bSawUnary) 
  {
    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    $oExpr;
    if ($this->fnIsContextual("await") && ($this->fnInAsync() || (!$this->fnInFunction() && $this->aOptions['allowAwaitOutsideFunction']))) {
      $oExpr = $this->fnParseAwait();
      $bSawUnary = true;
    } else if ($this->oType->bPrefix) {
      $oNode = $this->fnStartNode();
      $bUpdate = $this->oType === TokenTypes::$aTypes['incDec'];
      $oNode->sOperator = $this->mValue;
      $oNode->bPrefix = true;
      $this->fnNext();
      $oNode->oArgument = $this->fnParseMaybeUnary(null, true);
      $this->fnCheckExpressionErrors($oRefDestructuringErrors, true);
      
      if ($bUpdate) 
        $this->fnCheckLVal($oNode->oArgument);
      elseif ($this->bStrict 
              && $oNode->sOperator === "delete" 
              && $oNode->oArgument->sType === "Identifier")
        $this->fnRaiseRecoverable($oNode->iStart, "Deleting local variable in strict mode");
      else 
        $bSawUnary = true;
      $oExpr = $this->fnFinishNode($oNode, $bUpdate ? "UpdateExpression" : "UnaryExpression");
    } else {
      $oExpr = $this->fnParseExprSubscripts($oRefDestructuringErrors);
      if ($this->fnCheckExpressionErrors($oRefDestructuringErrors)) 
        return $oExpr;
      while ($this->oType->bPostfix && !$this->fnCanInsertSemicolon()) {
        $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
        $oNode->sOperator = $this->mValue;
        $oNode->bPrefix = false;
        $oNode->oArgument = $oExpr;
        $this->fnCheckLVal($oExpr);
        $this->fnNext();
        $oExpr = $this->fnFinishNode($oNode, "UpdateExpression");
      }
    }

    if (!$bSawUnary && $this->fnEat(TokenTypes::$aTypes['starstar']))
      return $this->fnBuildBinary($iStartPos, $oStartLoc, $oExpr, $this->fnParseMaybeUnary(null, false), "**", false);
    else
      return $oExpr;
  }

  // Parse call, dot, and `[]`-subscript expressions.

  public function fnParseExprSubscripts($oRefDestructuringErrors) 
  {
    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    $oExpr = $this->fnParseExprAtom($oRefDestructuringErrors);
    $bSkipArrowSubscripts = $oExpr->sType === "ArrowFunctionExpression" 
      && mb_substr($this->sInput, $this->iLastTokStart, $this->iLastTokEnd - $this->iLastTokStart) !== ")";
    if ($this->fnCheckExpressionErrors($oRefDestructuringErrors) || $bSkipArrowSubscripts) 
      return $oExpr;
    $oResult = $this->fnParseSubscripts($oExpr, $iStartPos, $oStartLoc);
    if ($oRefDestructuringErrors && $oResult->sType === "MemberExpression") {
      if ($oRefDestructuringErrors->iParenthesizedAssign >= $oResult->iStart) 
        $oRefDestructuringErrors->iParenthesizedAssign = -1;
      if ($oRefDestructuringErrors->iParenthesizedBind >= $oResult->iStart) 
        $oRefDestructuringErrors->iParenthesizedBind = -1;
    }
    return $oResult;
  }

  public function fnParseSubscripts($oBase, $iStartPos, $oStartLoc, $bNoCalls) 
  {
    $bMaybeAsyncArrow = $this->aOptions['ecmaVersion'] >= 8 
      && $oBase->sType === "Identifier" 
      && $oBase->sName === "async" 
      && $this->iLastTokEnd === $oBase->iEnd 
      && !$this->fnCanInsertSemicolon() 
      && mb_substr($this->sInput, $oBase->iStart, $oBase->iEnd - $oBase->iStart) === "async";
    for ($bComputed;;) {
      if (($bComputed = $this->fnEat(TokenTypes::$aTypes['bracketL'])) 
           || $this->fnEat(TokenTypes::$aTypes['dot'])) {
        
        $oNode = $this->fnStartNodeAt(startPos, startLoc);
        $oNode->oObject = $oBase;
        $oNode->oProperty = $bComputed ? $this->fnParseExpression() : $this->fnParseIdent(true);
        $oNode->bComputed = !!$bComputed;
        
        if ($bComputed) 
          $this->fnExpect(TokenTypes::$aTypes['bracketR']);
        
        $oBase = $this->fnFinishNode($oNode, "MemberExpression");
      } else if (!$bNoCalls && $this->fnEat(TokenTypes::$aTypes['parenL'])) {
        $oRefDestructuringErrors = new DestructuringErrors();
        $iOldYieldPos = $this->iYieldPos;
        $iOldAwaitPos = $this->iAwaitPos;
        $this->iYieldPos = 0;
        $this->iAwaitPos = 0;
        $aExprList = $this->fnParseExprList(TokenTypes::$aTypes['parenR'], $this->aOptions['ecmaVersion'] >= 8, false, $oRefDestructuringErrors);
        if ($bMaybeAsyncArrow && !$this->fnCanInsertSemicolon() && $this->fnEat(TokenTypes::$aTypes['arrow'])) {
          $this->fnCheckPatternErrors($oRefDestructuringErrors, false);
          $this->fnCheckYieldAwaitInDefaultParams();
          $this->iYieldPos = $iOldYieldPos;
          $this->iAwaitPos = $iOldAwaitPos;
          return $this->fnParseArrowExpression($this->fnStartNodeAt($iStartPos, $oStartLoc), $aExprList, true);
        }
        $this->fnCheckExpressionErrors($oRefDestructuringErrors, true);
        $this->iYieldPos = $iOldYieldPos ? $iOldYieldPos : $this->iYieldPos;
        $this->iAwaitPos = $iOldAwaitPos ? $iOldAwaitPos : $this->iAwaitPos;
        $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
        $oNode->oCallee = $oBase;
        $oNode->aArguments = $aExprList;
        $oBase = $this->fnFinishNode($oNode, "CallExpression");
      } else if ($this->sType === TokenTypes::$aTypes['backQuote']) {
        $oNode = $this->fnStartNodeAt($iStartPos, $oStartLoc);
        $oNode->oTag = $oBase;
        $oNode->oQuasi = $this->fnParseTemplate(['bIsTagged' => true]); //?
        $oBase = $this->fnFinishNode($oNode, "TaggedTemplateExpression");
      } else {
        return $oBase;
      }
    }
  }

  // Parse an atomic expression — either a single token that is an
  // expression, an expression started by a keyword like `function` or
  // `new`, or an expression wrapped in punctuation like `()`, `[]`,
  // or `{}`.

  public function fnParseExprAtom($oRefDestructuringErrors) 
  {
    $oNode;
    $bCanBeArrow = $this->iPotentialArrowAt === $this->iStart;
    switch ($this->oType) {
      case TokenTypes::$aTypes['super']:
        if (!$this->inFunction)
          $this->fnRaise($this->iStart, "'super' outside of function or class");
        $oNode = $this->fnStartNode();
        $this->fnNext();
        // The `super` keyword can appear at below:
        // SuperProperty:
        //     super [ Expression ]
        //     super . IdentifierName
        // SuperCall:
        //     super Arguments
        if ($this->oType !== TokenTypes::$aTypes['dot'] && $this->oType !== TokenTypes::$aTypes['bracketL'] && $this->oType !== TokenTypes::$aTypes['parenL'])
          $this->fnUnexpected();
        return $this->fnFinishNode($oNode, "Super");

      case TokenTypes::$aTypes['this']:
        $oNode = $this->fnStartNode();
        $this->fnNext();
        return $this->fnFinishNode($oNode, "ThisExpression");

      case TokenTypes::$aTypes['name']:
        $iStartPos = $this->iStart;
        $oStartLoc = $this->oStartLoc;
        $bContainsEsc = $this->bContainsEsc;
        $oId = $this->fnParseIdent($this->oType != TokenTypes::$aTypes['name']);
        if ($this->aOptions['ecmaVersion'] >= 8 
            && !$bContainsEsc && $oId->sName === "async" 
            && !$this->fnCanInsertSemicolon() 
            && $this->fnEat(TokenTypes::$aTypes['function']))
          return $this->fnParseFunction($this->fnStartNodeAt($iStartPos, $oStartLoc), 0, false, true);
        if ($bCanBeArrow && !$this->fnCanInsertSemicolon()) {
          if ($this->fnEat(TokenTypes::$aTypes['arrow']))
            return $this->fnParseArrowExpression($this->fnStartNodeAt($iStartPos, $oStartLoc), [$oId], false);
          if ($this->aOptions['ecmaVersion'] >= 8 
              && $oId->sName === "async" 
              && $this->oType === TokenTypes::$aTypes['name'] 
              && !$bContainsEsc) {
            $oId = $this->fnParseIdent();
            if ($this->fnCanInsertSemicolon() 
                || !$this->fnEat(TokenTypes::$aTypes['arrow']))
              $this->fnUnexpected();
            return $this->fnParseArrowExpression($this->fnStartNodeAt($iStartPos, $oStartLoc), [$oId], true);
          }
        }
        return $oId;

      case TokenTypes::$aTypes['regexp']:
        $mValue = $this->mValue;
        $oNode = $this->fnParseLiteral($mValue->sValue);
        $oNode->aRegex = [ 'sPattern' => $mValue->sPattern, 'sFlags' => $mValue->sFlags]; //?
        return $oNode;

      case TokenTypes::$aTypes['num']: 
      case TokenTypes::$aTypes['string']:
        return $this->fnParseLiteral($this->mValue);

      case TokenTypes::$aTypes['null']: 
      case TokenTypes::$aTypes['true']: 
      case TokenTypes::$aTypes['false']:
        $oNode = $this->fnStartNode();
        $oNode->mValue = $this->oType == TokenTypes::$aTypes['null'] ? null : $this->oType === TokenTypes::$aTypes['true'];
        $oNode->sRaw = $this->oType->sKeyword;
        $this->fnNext();
        return $this->fnFinishNode($oNode, "Literal");

      case TokenTypes::$aTypes['parenL']:
        $iStart = $this->iStart;
        $oExpr = $this->fnParseParenAndDistinguishExpression($bCanBeArrow);
        if ($oRefDestructuringErrors) {
          if ($oRefDestructuringErrors->iParenthesizedAssign < 0 && !$this->fnIsSimpleAssignTarget($oExpr))
            $oRefDestructuringErrors->iParenthesizedAssign = $iStart;
          if ($oRefDestructuringErrors->iParenthesizedBind < 0)
            $oRefDestructuringErrors->iParenthesizedBind = $iStart;
        }
        return $oExpr;

      case TokenTypes::$aTypes['bracketL']:
        $oNode = $this->fnStartNode();
        $this->fnNext();
        $oNode->aElements = $this->fnParseExprList(TokenTypes::$aTypes['bracketR'], true, true, $oRefDestructuringErrors);
        return $this->fnFinishNode($oNode, "ArrayExpression");

      case TokenTypes::$aTypes['braceL']:
        return $this->fnParseObj(false, $oRefDestructuringErrors);

      case TokenTypes::$aTypes['function']:
        $oNode = $this->fnStartNode();
        $this->fnNext();
        return $this->fnParseFunction($oNode, 0);

      case TokenTypes::$aTypes['class']:
        return $this->fnParseClass($this->fnStartNode(), false);

      case TokenTypes::$aTypes['new']:
        return $this->fnParseNew();

      case TokenTypes::$aTypes['backQuote']:
        return $this->fnParseTemplate();

      default:
        $this->fnUnexpected();
    }
  }

  public function fnParseLiteral($mValue) 
  {
    $oNode = $this->fnStartNode();
    $oNode->mValue = $mValue;
    $oNode->sRaw = mb_substr($this->sInput, $this->iStart, $this->iEnd - $this->iStart);
    $this->fnNext();
    return $this->fnFinishNode($oNode, "Literal");
  }

  public function fnParseParenExpression() 
  {
    $this->fnExpect(TokenTypes::$aTypes['parenL']);
    $mVal = $this->fnParseExpression();
    $this->fnExpect(TokenTypes::$aTypes['parenR']);
    return $mVal;
  }

  public function fnParseParenAndDistinguishExpression($bCanBeArrow) 
  {
    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    $mVal;
    $bAllowTrailingComma = $this->aOptions['ecmaVersion'] >= 8;
    if ($this->aOptions['ecmaVersion'] >= 6) {
      $this->fnNext();

      $iInnerStartPos = $this->iStart;
      $oInnerStartLoc = $this->oStartLoc;
      $aExprList = [];
      $bFirst = true;
      $bLastIsComma = false;
      $oRefDestructuringErrors = new DestructuringErrors();
      $iOldYieldPos = $this->iYieldPos;
      $iOldAwaitPos = $this->iAwaitPos;
      $iSpreadStart;
      $this->iYieldPos = 0;
      $this->iAwaitPos = 0;
      while ($this->oType != TokenTypes::$aTypes['parenR']) {
        $bFirst ? $bFirst = false : $this->fnExpect(TokenTypes::$aTypes['comma']);
        if ($bAllowTrailingComma 
            && $this->fnAfterTrailingComma(TokenTypes::$aTypes['parenR'], true)) {
          $bLastIsComma = true;
          break;
        } else if ($this->oType == TokenTypes::$aTypes['ellipsis']) {
          $iSpreadStart = $this->iStart;
          array_push($aExprList, $this->fnParseParenItem($this->fnParseRestBinding()));
          if ($this->oType == TokenTypes::$aTypes['comma']) 
            $this->fnRaise($this->iStart, "Comma is not permitted after the rest element");
          break;
        } else {
          array_push($aExprList, $this->fnParseMaybeAssign(false, $oRefDestructuringErrors, $this->fnParseParenItem));
        }
      }
      $iInnerEndPos = $this->iStart;
      $oInnerEndLoc = $this->oStartLoc;
      $this->fnExpect(TokenTypes::$aTypes['parenR']);

      if ($bCanBeArrow && !$this->fnCanInsertSemicolon() && $this->fnEat(TokenTypes::$aTypes['arrow'])) {
        $this->fnCheckPatternErrors($oRefDestructuringErrors, false);
        $this->fnCheckYieldAwaitInDefaultParams();
        $this->iYieldPos = $iOldYieldPos;
        $this->iAwaitPos = $iOldAwaitPos;
        return $this->fnParseParenArrowList($iStartPos, $oStartLoc, $aExprList);
      }

      if (!count($aExprList) || $bLastIsComma) 
        $this->fnUnexpected($this->oLastTokStart);
      if ($iSpreadStart) 
        $this->fnUnexpected($iSpreadStart);
      
      $this->fnCheckExpressionErrors($oRefDestructuringErrors, true);
      $this->iYieldPos = $iOldYieldPos ? $iOldYieldPos : $this->iYieldPos;
      $this->iAwaitPos = $iOldAwaitPos ? $iOldAwaitPos : $this->iAwaitPos;

      if (count($aExprList) > 1) {
        $mVal = $this->fnStartNodeAt($iInnerStartPos, $oInnerStartLoc);
        $mVal->aExpressions = $aExprList;
        $this->fnFinishNodeAt($mVal, "SequenceExpression", $iInnerEndPos, $oInnerEndLoc);
      } else {
        $mVal = $aExprList[0];
      }
    } else {
      $mVal = $this->fnParseParenExpression();
    }

    if ($this->aOptions['preserveParens']) {
      $oPar = $this->fnStartNodeAt($iStartPos, $oStartLoc);
      $oPar->oExpression = $mVal;
      return $this->fnFinishNode($oPar, "ParenthesizedExpression");
    } else {
      return $mVal;
    }
  }

  public function fnParseParenItem($oItem) 
  {
    return $oItem;
  }

  public function fnParseParenArrowList($iStartPos, $oStartLoc, $aExprList) 
  {
    return $this->fnParseArrowExpression($this->fnStartNodeAt($iStartPos, $oStartLoc), $aExprList);
  }

  // New's precedence is slightly tricky. It must allow its argument to
  // be a `[]` or dot subscript expression, but not a call — at least,
  // not without wrapping it in parentheses. Thus, it uses the noCalls
  // argument to parseSubscripts to prevent it from consuming the
  // argument list.

  public function fnParseNew() 
  {
    $oNode = $this->fnStartNode();
    $oMeta = $this->fnParseIdent(true);
    if ($this->aOptions['ecmaVersion'] >= 6 
        && $this->fnEat(TokenTypes::$aTypes['dot'])) {
      $oNode->oMeta = $oMeta;
      $bContainsEsc = $this->bContainsEsc;
      $oNode->oProperty = $this->fnParseIdent(true);
      if ($oNode->oProperty->sName !== "target" || $bContainsEsc)
        $this->fnRaiseRecoverable($oNode->oProperty->iStart, "The only valid meta property for new is new.target");
      if (!$this->inNonArrowFunction())
        $this->fnRaiseRecoverable($oNode->iStart, "new.target can only be used in functions");
      return $this->fnFinishNode($oNode, "MetaProperty");
    }
    $iStartPos = $this->iStart;
    $oStartLoc = $this->oStartLoc;
    $oNode->oCallee = $this->fnParseSubscripts($this->fnParseExprAtom(), $iStartPos, $oStartLoc, true);
    if ($this->fnEat(TokenTypes::$aTypes['parenL'])) 
      $oNode->aArguments = $this->fnParseExprList(TokenTypes::$aTypes['parenR'], $this->aOptions['ecmaVersion'] >= 8, false);
    else 
      $oNode->aArguments = [];
    return $this->fnFinishNode($oNode, "NewExpression");
  }

  // Parse template expression.

  public function fnParseTemplateElement($aObject) 
  {
    $bIsTagged = $aObject['bIsTagged'];
    $oElem = $this->fnStartNode();
    if ($this->oType == TokenTypes::$aTypes['invalidTemplate']) {
      if (!$bIsTagged) {
        $this->fnRaiseRecoverable($this->iStart, "Bad escape sequence in untagged template literal");
      }
      $oElem->mValue = [
        'mRaw' => $this->mValue,
        'mCooked' => null,
      ];
    } else {
      $oElem->mValue = [
        'mRaw' => preg_replace("/\r\n?/", "\n", mb_substr($this->sInput, $this->iStart, $this->iEnd - $this->iStart)),
        'mCooked' => $this->mValue,
      ];
    }
    $this->fnNext();
    $oElem->bTail = $this->oType == TokenTypes::$aTypes['backQuote'];
    return $this->fnFinishNode($oElem, "TemplateElement");
  }

  public function fnparseTemplate($aObject = []) 
  {
    $bIsTagged = $aObject['bIsTagged'];
    $oNode = $this->fnStartNode();
    $this->fnNext();
    $oNode->aExpressions = [];
    $oCurElt = $this->fnParseTemplateElement(['bIsTagged' => $bIsTagged]);
    $oNode->aQuasis = [$oCurElt];
    while (!$oCurElt->bTail) {
      if ($this->oType == TokenTypes::$aTypes['eof']) 
        $this->fnRaise($this->iPos, "Unterminated template literal");
      $this->fnExpect(TokenTypes::$aTypes['dollarBraceL']);
      array_push($oNode->aExpressions, $this->fnParseExpression());
      $this->fnExpect(TokenTypes::$aTypes['braceR']);
      array_push($oNode->aQuasis, $oCurElt = $this->fnParseTemplateElement(['bIsTagged' => $bIsTagged]));
    }
    $this->fnNext();
    return $this->fnFinishNode($oNode, "TemplateLiteral");
  }

  public function fnIsAsyncProp($oProp) 
  {
    return !$oProp->bComputed //?
      && $oProp->oKey->sType === "Identifier" 
      && $oProp->oKey->sName === "async" 
      && ($this->oType == TokenTypes::$aTypes['name'] 
          || $this->oType == TokenTypes::$aTypes['num'] 
          || $this->oType == TokenTypes::$aTypes['string'] 
          || $this->oType == TokenTypes::$aTypes['bracketL'] 
          || $this->oType->sKeyword 
          || ($this->aOptions['ecmaVersion'] >= 9 
          && $this->oType == TokenTypes::$aTypes['star'])) 
      && !preg_match(Whitespace::lineBreak, mb_substr($this->sInput, $this->iLastTokEnd, $this->iStart - $this->iLastTokEnd));
  }

  // Parse an object literal or binding pattern.

  public function fnParseObj($bIsPattern, $oRefDestructuringErrors) 
  {
    $oNode = $this->fnStartNode();
    $bFirst = true;
    $aPropHash = [];
    $oNode->aProperties = [];
    $this->fnNext();
    while (!$this->fnEat(TokenTypes::$aTypes['braceR'])) {
      if (!$bFirst) {
        $this->fnExpect(TokenTypes::$aTypes['comma']);
        if ($this->fnAfterTrailingComma(TokenTypes::$aTypes['braceR'])) 
          break;
      } else 
        $bFirst = false;

      $oProp = $this->fnParseProperty($bIsPattern, $oRefDestructuringErrors);
      if (!$bIsPattern) 
        $this->fnCheckPropClash($oProp, $aPropHash, $oRefDestructuringErrors);
      array_push($oNode->aProperties, $oProp);
    }
    return $this->fnFinishNode($oNode, $bIsPattern ? "ObjectPattern" : "ObjectExpression");
  }

  public function fnParseProperty($bIsPattern, $oRefDestructuringErrors) 
  {
    $oProp = $this->fnStartNode();
    $bIsGenerator;
    $bIsAsync;
    $iStartPos;
    $oStartLoc;
    if ($this->aOptions['ecmaVersion'] >= 9 
        && $this->fnEat(TokenTypes::$aTypes['ellipsis'])) {
      if ($bIsPattern) {
        $oProp->oArgument = $this->fnParseIdent(false);
        if ($this->oType == TokenTypes::$aTypes['comma']) {
          $this->fnRaise($this->iStart, "Comma is not permitted after the rest element");
        }
        return $this->fnFinishNode($oProp, "RestElement");
      }
      // To disallow parenthesized identifier via `$this->toAssignable()`.
      if ($this->oType == TokenTypes::$aTypes['parenL'] && $oRefDestructuringErrors) {
        if ($oRefDestructuringErrors->iParenthesizedAssign < 0) {
          $oRefDestructuringErrors->iParenthesizedAssign = $this->iStart;
        }
        if ($oRefDestructuringErrors->iParenthesizedBind < 0) {
          $oRefDestructuringErrors->iParenthesizedBind = $this->iStart;
        }
      }
      // Parse argument.
      $oProp->oArgument = $this->fnParseMaybeAssign(false, $oRefDestructuringErrors);
      // To disallow trailing comma via `$this->toAssignable()`.
      if ($this->oType == TokenTypes::$aTypes['comma'] 
          && $oRefDestructuringErrors 
          && $oRefDestructuringErrors->iTrailingComma < 0) {
        $oRefDestructuringErrors->iTrailingComma = $this->iStart;
      }
      // Finish
      return $this->fnFinishNode($oProp, "SpreadElement");
    }
    if ($this->aOptions['ecmaVersion'] >= 6) {
      $oProp->bMethod = false;
      $oProp->bShorthand = false;
      if ($bIsPattern || $oRefDestructuringErrors) {
        $iStartPos = $this->iStart;
        $oStartLoc = $this->oStartLoc;
      }
      if (!$bIsPattern)
        $bIsGenerator = $this->fnEat(TokenTypes::$aTypes['star']);
    }
    $bContainsEsc = $this->bContainsEsc;
    $this->fnParsePropertyName($oProp);
    if (!$bIsPattern 
         && !$bContainsEsc 
         && $this->aOptions['ecmaVersion'] >= 8 
         && !$bIsGenerator 
         && $this->fnIsAsyncProp($oProp)) {
      $bIsAsync = true;
      $bIsGenerator = $this->aOptions['ecmaVersion'] >= 9 
        && $this->fnEat(TokenTypes::$aTypes['star']);
      $this->fnParsePropertyName($oProp, $oRefDestructuringErrors);
    } else {
      $bIsAsync = false;
    }
    $this->fnParsePropertyValue($oProp, $bIsPattern, $bIsGenerator, $bIsAsync, $iStartPos, $oStartLoc, $oRefDestructuringErrors, $bContainsEsc);
    return $this->fnFinishNode($oProp, "Property");
  }

  public function fnParsePropertyValue(&$oProp, $bIsPattern, $bIsGenerator, $bIsAsync, $iStartPos, $oStartLoc, $oRefDestructuringErrors, $bContainsEsc) 
  {
    if (($bIsGenerator || $bIsAsync) 
         && $this->oType == TokenTypes::$aTypes['colon'])
      $this->fnUnexpected();

    if ($this->fnEat(TokenTypes::$aTypes['colon'])) {
      $oProp->mValue = $bIsPattern ? $this->fnParseMaybeDefault($this->iStart, $this->oStartLoc) : $this->fnParseMaybeAssign(false, $oRefDestructuringErrors);
      $oProp->sKind = "init";
    } else if ($this->aOptions['ecmaVersion'] >= 6 
               && $this->oType == TokenTypes::$aTypes['parenL']) {
      if ($bIsPattern) 
        $this->fnUnexpected();
      $oProp->sKind = "init";
      $oProp->bMethod = true;
      $oProp->mValue = $this->fnParseMethod($bIsGenerator, $bIsAsync);
    } else if (!$bIsPattern 
                && !$bContainsEsc 
                && $this->aOptions['ecmaVersion'] >= 5 
                && !$oProp->bComputed 
                && $oProp->oKey->sType === "Identifier" 
                && ($oProp->oKey->sName === "get" 
                    || $oProp->oKey->sName === "set") 
                && ($this->oType != TokenTypes::$aTypes['comma'] 
                    && $this->oType != TokenTypes::$aTypes['braceR'])) {
      if ($bIsGenerator || $bIsAsync) 
        $this->fnUnexpected();
      $oProp->sKind = $oProp->oKey->sName;
      $this->fnParsePropertyName($oProp);
      $oProp->mValue = $this->fnParseMethod(false);
      $iParamCount = $oProp->sKind === "get" ? 0 : 1;
      if (count($oProp->mValue->aParams) !== $iParamCount) {
        $iStart = $oProp->mValue->iStart;
        if ($oProp->sKind === "get")
          $this->fnRaiseRecoverable($iStart, "getter should have no params");
        else
          $this->fnRaiseRecoverable($iStart, "setter should have exactly one param");
      } else {
        if ($oProp->sKind === "set" 
            && $oProp->mValue->aParams[0]->sType === "RestElement")
          $this->fnRaiseRecoverable($oProp->mValue->aParams[0]->iStart, "Setter cannot use rest params");
      }
    } else if ($this->aOptions['ecmaVersion'] >= 6 && !$oProp->bComputed && $oProp->oKey->sType === "Identifier") {
      $this->fnCheckUnreserved($oProp->oKey);
      $oProp->sKind = "init";
      if ($bIsPattern) {
        $oProp->mValue = $this->fnParseMaybeDefault($iStartPos, $oStartLoc, $oProp->oKey);
      } else if ($this->oType == TokenTypes::$aTypes['eq'] && $oRefDestructuringErrors) {
        if ($oRefDestructuringErrors->iShorthandAssign < 0)
          $oRefDestructuringErrors->iShorthandAssign = $this->iStart;
        $oProp->mValue = $this->fnParseMaybeDefault($iStartPos, $oStartLoc, $oProp->oKey);
      } else {
        $oProp->mValue = $oProp->oKey;
      }
      $oProp->bShorthand = true;
    } else 
      $this->fnUnexpected();
  }

  public function fnParsePropertyName(&$oProp)
  {
    if ($this->aOptions['ecmaVersion'] >= 6) {
      if ($this->fnEat(TokenTypes::$aTypes['bracketL'])) {
        $oProp->bComputed = true;
        $oProp->oKey = $this->fnParseMaybeAssign();
        $this->fnExpect(TokenTypes::$aTypes['bracketR']);
        return $oProp->oKey;
      } else {
        $oProp->bComputed = false;
      }
    }
    return $oProp->oKey = $this->oType == TokenTypes::$aTypes['num'] 
            || $this->oType == TokenTypes::$aTypes['string'] ? 
              $this->fnParseExprAtom() : 
              $this->fnParseIdent(true);
  }

  // Initialize empty function node.

  public function fnInitFunction(&$oNode) 
  {
    $oNode->oId = null;
    if ($this->aOptions['ecmaVersion'] >= 6) 
      $oNode->bGenerator = $oNode->oExpression = false;
    if ($this->aOptions['ecmaVersion'] >= 8) 
      $oNode->bAsync = false;
  }

  // Parse object or class method.

  public function fnParseMethod($bIsGenerator, $bIsAsync) 
  {
    $oNode = $this->fnStartNode();
    $iOldYieldPos = $this->iYieldPos;
    $iOldAwaitPos = $this->iAwaitPos;

    $this->fnInitFunction($oNode);
    if ($this->aOptions['ecmaVersion'] >= 6)
      $oNode->bGenerator = $bIsGenerator;
    if ($this->aOptions['ecmaVersion'] >= 8)
      $oNode->bAsync = !!$bIsAsync;

    $this->iYieldPos = 0;
    $this->iAwaitPos = 0;
    $this->fnEnterScope(Scope::fnFunctionFlags($bIsAsync, $oNode->bGenerator));

    $this->fnExpect(TokenTypes::$aTypes['parenL']);
    $oNode->aParams = $this->fnParseBindingList(TokenTypes::$aTypes['parenR'], false, $this->aOptions['ecmaVersion'] >= 8);
    $this->fnCheckYieldAwaitInDefaultParams();
    $this->fnParseFunctionBody(node, false);

    $this->iYieldPos = $iOldYieldPos;
    $this->iAwaitPos = $iOldAwaitPos;
    return $this->fnFinishNode($oNode, "FunctionExpression");
  }

  // Parse arrow function expression with given parameters.

  public function fnParseArrowExpression(&$oNode, $aParams, $bIsAsync) 
  {
    $iOldYieldPos = $this->iYieldPos;
    $iOldAwaitPos = $this->iAwaitPos;

    $this->fnEnterScope(Scope::fnFunctionFlags($bIsAsync, false) | Scope::SCOPE_ARROW);
    $this->fnInitFunction($oNode);
    if ($this->aOptions['ecmaVersion'] >= 8) 
      $oNode->bAsync = !!$bIsAsync;

    $this->iYieldPos = 0;
    $this->iAwaitPos = 0;

    $oNode->aParams = $this->toAssignableList($aParams, true);
    $this->fnParseFunctionBody($oNode, true);

    $this->iYieldPos = $iOldYieldPos;
    $this->iAwaitPos = $iOldAwaitPos;
    return $this->fnFinishNode($oNode, "ArrowFunctionExpression");
  }

  // Parse function body and check parameters.

  public function fnParseFunctionBody(&$oNode, $bIsArrowFunction) 
  {
    $bIsExpression = $bIsArrowFunction && $this->oType != TokenTypes::$aTypes['braceL'];
    $bOldStrict = $this->bStrict;
    $bUseStrict = false;

    if ($bIsExpression) {
      $oNode->aBody = $this->fnParseMaybeAssign();
      $oNode->oExpression = true;
      $this->fnCheckParams($oNode, false);
    } else {
      $bNonSimple = $this->aOptions['ecmaVersion'] >= 7 
        && !$this->fnIsSimpleParamList($oNode->aParams);
      if (!$bOldStrict || $bNonSimple) {
        $bUseStrict = $this->fnStrictDirective($this->iEnd);
        // If this is a strict mode function, verify that argument names
        // are not repeated, and it does not try to bind the words `eval`
        // or `arguments`.
        if ($bUseStrict && $bNonSimple)
          $this->fnRaiseRecoverable($oNode->iStart, "Illegal 'use strict' directive in function with non-simple parameter list");
      }
      // Start a new scope with regard to labels and the `inFunction`
      // flag (restore them to their old value afterwards).
      $aOldLabels = $this->aLabels;
      $this->aLabels = [];
      if ($bUseStrict) 
        $this->bStrict = true;

      // Add the params to varDeclaredNames to ensure that an error is thrown
      // if a let/const declaration in the function clashes with one of the params.
      $this->fnCheckParams($oNode, !$bOldStrict && !$bUseStrict && !$bIsArrowFunction && $this->fnIsSimpleParamList($oNode->aParams));
      $oNode->aBody = $this->fnParseBlock(false);
      $oNode->oExpression = false;
      $this->fnAdaptDirectivePrologue($oNode->aBody->aBody);
      $this->aLabels = aOldLabels;
    }
    $this->fnExitScope();

    // Ensure the function name isn't a forbidden identifier in strict mode, e.g. 'eval'
    if ($this->bStrict && $oNode->oId) 
      $this->fnCheckLVal($oNode->oId, Scope::BIND_OUTSIDE);
    
    $this->bStrict = $bOldStrict;
  }

  public function fnIsSimpleParamList($aParams) 
  {
    foreach ($aParams as $oParam)
      if ($oParam->sType !== "Identifier") 
        return false;
    return true;
  }

  // Checks function params for various disallowed patterns such as using "eval"
  // or "arguments" and duplicate parameters.

  public function fnCheckParams(&$oNode, $bAllowDuplicates) 
  {
    $aNameHash = [];
    foreach ($oNode->aParams as &$oParam)
      $this->fnCheckLVal($oParam, Scope::BIND_VAR, $bAllowDuplicates ? null : $aNameHash);
  }

  // Parses a comma-separated list of expressions, and returns them as
  // an array. `close` is the token type that ends the list, and
  // `allowEmpty` can be turned on to allow subsequent commas with
  // nothing in between them to be parsed as `null` (which is needed
  // for array literals).

  public function fnParseExprList($oClose, $bAllowTrailingComma, $bAllowEmpty, $oRefDestructuringErrors) 
  {
    $aElts = [];
    $bFirst = true;
    while (!$this->fnEat($oClose)) {
      if (!$bFirst) {
        $this->fnExpect(TokenTypes::$aTypes['comma']);
        if ($bAllowTrailingComma && $this->fnAfterTrailingComma($oClose)) 
          break;
      } else 
        $bFirst = false;

      $oElt;
      if ($bAllowEmpty && $this->oType == TokenTypes::$aTypes['comma'])
        $oElt = null;
      else if ($this->oType == TokenTypes::$aTypes['ellipsis']) {
        $oElt = $this->fnParseSpread($oRefDestructuringErrors);
        if ($oRefDestructuringErrors && $this->oType == TokenTypes::$aTypes['comma'] && $oRefDestructuringErrors->iTrailingComma < 0)
          $oRefDestructuringErrors->iTrailingComma = $this->iStart;
      } else {
        $oElt = $this->fnParseMaybeAssign(false, $oRefDestructuringErrors);
      }
      array_push($aElts, $oElt);
    }
    return $aElts;
  }

  public function fnCheckUnreserved({start, end, name}) 
  {
    if ($this->fnInGenerator() && name === "yield")
      $this->fnRaiseRecoverable(start, "Can not use 'yield' as identifier inside a generator")
    if ($this->inAsync && name === "await")
      $this->fnRaiseRecoverable(start, "Can not use 'await' as identifier inside an async function")
    if ($this->keywords.test(name))
      $this->fnRaise(start, `Unexpected keyword '${name}'`)
    if ($this->aOptions['ecmaVersion'] < 6 &&
      $this->input.slice(start, end).indexOf("\\") !== -1) return
    const re = $this->strict ? $this->reservedWordsStrict : $this->reservedWords
    if (re.test(name)) {
      if (!$this->inAsync && name === "await")
        $this->fnRaiseRecoverable(start, "Can not use keyword 'await' outside an async function")
      $this->fnRaiseRecoverable(start, `The keyword '${name}' is reserved`)
    }
  }

  // Parse the next token as an identifier. If `liberal` is true (used
  // when parsing properties), it will also convert keywords into
  // identifiers.

  public function fnParseIdent(&$bLiberal, $bIsBinding) {
    $oNode = $this->fnStartNode()
    if ($bLiberal && $this->aOptions['allowReserved'] === "never") 
      $bLiberal = false;
    if ($this->oType == TokenTypes::$aTypes['name']) {
      $oNode->sName = $this->mValue
    } else if ($this->oType->sKeyword) {
      $oNode->sName = $this->oType->sKeyword

      // To fix https://github.com/acornjs/acorn/issues/575
      // `class` and `function` keywords push new context into $this->context.
      // But there is no chance to pop the context if the keyword is consumed as an identifier such as a property name.
      // If the previous token is a dot, this does not apply because the context-managing code already ignored the keyword
      if (($oNode->sName === "class" 
             || $oNode->sName === "function") 
           && ($this->iLastTokEnd !== $this->iLastTokStart + 1 
               || Utilities::fnGetCharAt($this->sInput, $this->iLastTokStart) !== 46)) {
        array_pop($this->aContext);
      }
    } else {
      $this->fnUnexpected()
    }
    $this->fnNext()
    $this->fnFinishNode(node, "Identifier")
    if (!$bLiberal) 
      $this->fnCheckUnreserved($oNode);
    return $oNode;
  }

  // Parses yield expression inside generator.

  public function fnParseYield() 
  {
    if (!$this->iYieldPos) 
      $this->iYieldPos = $this->iStart;

    $oNode = $this->fnStartNode();
    $this->fnNext();
    if ($this->oType == TokenTypes::$aTypes['semi'] 
        || $this->fnCanInsertSemicolon() 
        || ($this->oType != TokenTypes::$aTypes['star'] 
            && !$this->oType.startsExpr)) {
      $oNode->bDelegate = false;
      $oNode->oArgument = null;
    } else {
      $oNode->bDelegate = $this->fnEat(TokenTypes::$aTypes['star']);
      $oNode->oArgument = $this->fnParseMaybeAssign();
    }
    return $this->fnFinishNode($oNode, "YieldExpression");
  }

  public function fnParseAwait = function() 
  {
    if (!$this->iAwaitPos) 
      $this->iAwaitPos = $this->iStart;

    $oNode = $this->fnStartNode();
    $this->fnNext();
    $oNode->oArgument = $this->fnParseMaybeUnary(null, true);
    return $this->fnFinishNode($oNode, "AwaitExpression");
  }
  
  public function fnInFunction() 
  { 
    return ($this->fnCurrentVarScope()->iFlags & Scope::SCOPE_FUNCTION) > 0;
  }
  
  public function fnInGenerator() 
  { 
    return ($this->fnCurrentVarScope()->iFlags & Scope::SCOPE_GENERATOR) > 0; 
  }
  public function fnInAsync() 
  {
    return ($this->fnCurrentVarScope()->iFlags & Scope::SCOPE_ASYNC) > 0;
  }
  
  public function fnCurPosition()
  {
    if ($this->aOptions['locations']) {
      return new Position($this->iCurLine, $this->iPos - $this->iLineStart);
    }
  }
  
  public function fnParseI()
  {
    $oNode = $this->aOptions['program'] || $this->fnStartNode();
    $this->fnNextToken();
    return $this->fnParseTopLevel($oNode);
  }
  
  public static function fnParse($sInput, $aOptions)
  {
    return (new self($aOptions, $sInput))->fnParseI();
  }
  
  public static function fnParseExpressionAt($sInput, $iPos, $aOptions)
  {
    $oParser = new self($aOptions, $sInput, $iPos);
    $oParser->fnNextToken();
    return $oParser->fnParseExpression();
  }
  
  public static function fnTokenizer($sInput, $aOptions)
  {
    return new self($aOptions, $sInput);
  }
  
  public static function fnExtend(...$aPlugins)
  {
    
  }
}

