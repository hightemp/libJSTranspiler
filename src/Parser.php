<?php

namespace libJSTranspiler;

use libJSTranspiler\Node;
use libJSTranspiler\Token;
use libJSTranspiler\TokenType;
use libJSTranspiler\TokenTypes;
use libJSTranspiler\Identifier;
use libJSTranspiler\Whitespace;
use libJSTranspiler\TokenContext;
use libJSTranspiler\Utilities;
use libJSTranspiler\Scope;
use Closure;

if (!function_exists('fnKeywordRegexp')) {
  function fnKeywordRegexp($sString)
  {
    return "/^(?:" . str_replace(" ", "|", $sString) . ")$/";
  }
}

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
  public $bExprAllowed;
  public $bInModule;
  public $iYieldPos;
  public $iAwaitPos;
  public $aLabels;
  public $aScopeStack;
  
  
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
        $sReserved += " await";
    }
    $this->sReservedWords = fnKeywordRegexp($sReserved);
    $sReservedStrict = ($sReserved ? $sReserved . " " : "") + Identifier::$aReservedWords['strict'];
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
    $this->strict = $this->bInModule || $this->fnStrictDirective($this->iPos);

    // Used to signify the start of a potential arrow function
    $this->potentialArrowAt = -1;

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

  public function _fnFinishNodeAt($oNode, $sType, $iPos, $oLoc) 
  {
    $oNode->sType = $sType;
    $oNode->iEnd = $iPos;
    if ($this->aOptions['locations'])
      $oNode->oLoc->oEnd = $oLoc;
    if ($this->aOptions['ranges'])
      $oNode->aRange[1] = $iPos;
    return $oNode;
  }

  public function fnFinishNode($oNode, $sType) 
  {
    return $this->_fnFinishNodeAt($oNode, $sType, $this->iLastTokEnd, $this->oLastTokEndLoc);
  }

  // Finish node at given position

  public function fnFinishNodeAt($oNode, $sType, $iPos, $oLoc) 
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
  
  public function fnSkipLineComment($iStartSkip) 
  {
    $iStart = $this->iPos;
    $oStartLoc = null;
    
    if (isset($this->aOptions['onComment']))
      $oStartLoc = $this->fnCurPosition();
    
    $iCh = Utilities::fnGetCharCodeAt($this->sInput, $this->iPos += $iStartSkip);
    while ($this->iPos < mb_strlen($this->sInput) && !isNewLine($iCh)) {
      $iCh = Utilities::fnGetCharCodeAt($this->sInput, ++$this->iPos);
    }
    if (is_callable($this->options['onComment'])) {
      $fnOnComment = Closure::bind($this->options['onComment'], $this);
      $fnOnComment(
        false, 
        mb_substr($this->sInput, $iStart + $iStartSkip, $this->iPos - $iStart - $iStartSkip),
        $iStart, 
        $this->iPos,
        $oStartLoc, 
        $this->fnCurPosition()
      );
    }
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

  public function getToken() 
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
            done: token.type === tt.eof,
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
    if (!$aCurContext || !isset($aCurContext['preserveSpace']))
      $this->fnSkipSpace();

    $this->iStart = $this->iPos;
    if ($this->aOptions['locations']) 
      $this->oStartLoc = $this->fnCurPosition();
    if ($this->iPos >= mb_strlen($this->sInput)) 
      return $this->fnFinishToken(TokenTypes::$aTypes['eof']);

    if (is_callable($aCurContext['override'])) 
      return $aCurContext['override']($this);
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
    
    $iStart = $this->pos;
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

  pp.readToken_lt_gt = function(code) { // '<>'
    let next = $this->input.charCodeAt($this->pos + 1)
    let size = 1
    if (next === code) {
      size = code === 62 && $this->input.charCodeAt($this->pos + 2) === 62 ? 3 : 2
      if ($this->input.charCodeAt($this->pos + size) === 61) return $this->finishOp(tt.assign, size + 1)
      return $this->finishOp(tt.bitShift, size)
    }
    if (next === 33 && code === 60 && !$this->inModule && $this->input.charCodeAt($this->pos + 2) === 45 &&
        $this->input.charCodeAt($this->pos + 3) === 45) {
      // `<!--`, an XML-style comment that should be interpreted as a line comment
      $this->skipLineComment(4)
      $this->skipSpace()
      return $this->nextToken()
    }
    if (next === 61) size = 2
    return $this->finishOp(tt.relational, size)
  }

  pp.readToken_eq_excl = function(code) { // '=!'
    let next = $this->input.charCodeAt($this->pos + 1)
    if (next === 61) return $this->finishOp(tt.equality, $this->input.charCodeAt($this->pos + 2) === 61 ? 3 : 2)
    if (code === 61 && next === 62 && $this->options.ecmaVersion >= 6) { // '=>'
      $this->pos += 2
      return $this->finishToken(tt.arrow)
    }
    return $this->finishOp(code === 61 ? tt.eq : tt.prefix, 1)
  }

  pp.getTokenFromCode = function(code) {
    switch (code) {
    // The interpretation of a dot depends on whether it is followed
    // by a digit or another two dots.
    case 46: // '.'
      return $this->readToken_dot()

    // Punctuation tokens.
    case 40: ++$this->pos; return $this->finishToken(tt.parenL)
    case 41: ++$this->pos; return $this->finishToken(tt.parenR)
    case 59: ++$this->pos; return $this->finishToken(tt.semi)
    case 44: ++$this->pos; return $this->finishToken(tt.comma)
    case 91: ++$this->pos; return $this->finishToken(tt.bracketL)
    case 93: ++$this->pos; return $this->finishToken(tt.bracketR)
    case 123: ++$this->pos; return $this->finishToken(tt.braceL)
    case 125: ++$this->pos; return $this->finishToken(tt.braceR)
    case 58: ++$this->pos; return $this->finishToken(tt.colon)
    case 63: ++$this->pos; return $this->finishToken(tt.question)

    case 96: // '`'
      if ($this->options.ecmaVersion < 6) break
      ++$this->pos
      return $this->finishToken(tt.backQuote)

    case 48: // '0'
      let next = $this->input.charCodeAt($this->pos + 1)
      if (next === 120 || next === 88) return $this->readRadixNumber(16) // '0x', '0X' - hex number
      if ($this->options.ecmaVersion >= 6) {
        if (next === 111 || next === 79) return $this->readRadixNumber(8) // '0o', '0O' - octal number
        if (next === 98 || next === 66) return $this->readRadixNumber(2) // '0b', '0B' - binary number
      }

    // Anything else beginning with a digit is an integer, octal
    // number, or float.
    case 49: case 50: case 51: case 52: case 53: case 54: case 55: case 56: case 57: // 1-9
      return $this->readNumber(false)

    // Quotes produce strings.
    case 34: case 39: // '"', "'"
      return $this->readString(code)

    // Operators are parsed inline in tiny state machines. '=' (61) is
    // often referred to. `finishOp` simply skips the amount of
    // characters it is given as second argument, and returns a token
    // of the type given by its first argument.

    case 47: // '/'
      return $this->readToken_slash()

    case 37: case 42: // '%*'
      return $this->readToken_mult_modulo_exp(code)

    case 124: case 38: // '|&'
      return $this->readToken_pipe_amp(code)

    case 94: // '^'
      return $this->readToken_caret()

    case 43: case 45: // '+-'
      return $this->readToken_plus_min(code)

    case 60: case 62: // '<>'
      return $this->readToken_lt_gt(code)

    case 61: case 33: // '=!'
      return $this->readToken_eq_excl(code)

    case 126: // '~'
      return $this->finishOp(tt.prefix, 1)
    }

    $this->raise($this->pos, "Unexpected character '" + codePointToString(code) + "'")
  }

  pp.finishOp = function(type, size) {
    let str = $this->input.slice($this->pos, $this->pos + size)
    $this->pos += size
    return $this->finishToken(type, str)
  }

  pp.readRegexp = function() {
    let escaped, inClass, start = $this->pos
    for (;;) {
      if ($this->pos >= $this->input.length) $this->raise(start, "Unterminated regular expression")
      let ch = $this->input.charAt($this->pos)
      if (lineBreak.test(ch)) $this->raise(start, "Unterminated regular expression")
      if (!escaped) {
        if (ch === "[") inClass = true
        else if (ch === "]" && inClass) inClass = false
        else if (ch === "/" && !inClass) break
        escaped = ch === "\\"
      } else escaped = false
      ++$this->pos
    }
    let pattern = $this->input.slice(start, $this->pos)
    ++$this->pos
    let flagsStart = $this->pos
    let flags = $this->readWord1()
    if ($this->containsEsc) $this->unexpected(flagsStart)

    // Validate pattern
    const state = $this->regexpState || ($this->regexpState = new RegExpValidationState(this))
    state.reset(start, pattern, flags)
    $this->validateRegExpFlags(state)
    $this->validateRegExpPattern(state)

    // Create Literal#value property value.
    let value = null
    try {
      value = new RegExp(pattern, flags)
    } catch (e) {
      // ESTree requires null if it failed to instantiate RegExp object.
      // https://github.com/estree/estree/blob/a27003adf4fd7bfad44de9cef372a2eacd527b1c/es5.md#regexpliteral
    }

    return $this->finishToken(tt.regexp, {pattern, flags, value})
  }

  // Read an integer in the given radix. Return null if zero digits
  // were read, the integer value otherwise. When `len` is given, this
  // will return `null` unless the integer has exactly `len` digits.

  pp.readInt = function(radix, len) {
    let start = $this->pos, total = 0
    for (let i = 0, e = len == null ? Infinity : len; i < e; ++i) {
      let code = $this->input.charCodeAt($this->pos), val
      if (code >= 97) val = code - 97 + 10 // a
      else if (code >= 65) val = code - 65 + 10 // A
      else if (code >= 48 && code <= 57) val = code - 48 // 0-9
      else val = Infinity
      if (val >= radix) break
      ++$this->pos
      total = total * radix + val
    }
    if ($this->pos === start || len != null && $this->pos - start !== len) return null

    return total
  }

  pp.readRadixNumber = function(radix) {
    $this->pos += 2 // 0x
    let val = $this->readInt(radix)
    if (val == null) $this->raise($this->start + 2, "Expected number in radix " + radix)
    if (isIdentifierStart($this->fullCharCodeAtPos())) $this->raise($this->pos, "Identifier directly after number")
    return $this->finishToken(tt.num, val)
  }

  // Read an integer, octal integer, or floating-point number.

  pp.readNumber = function(startsWithDot) {
    let start = $this->pos
    if (!startsWithDot && $this->readInt(10) === null) $this->raise(start, "Invalid number")
    let octal = $this->pos - start >= 2 && $this->input.charCodeAt(start) === 48
    if (octal && $this->strict) $this->raise(start, "Invalid number")
    if (octal && /[89]/.test($this->input.slice(start, $this->pos))) octal = false
    let next = $this->input.charCodeAt($this->pos)
    if (next === 46 && !octal) { // '.'
      ++$this->pos
      $this->readInt(10)
      next = $this->input.charCodeAt($this->pos)
    }
    if ((next === 69 || next === 101) && !octal) { // 'eE'
      next = $this->input.charCodeAt(++$this->pos)
      if (next === 43 || next === 45) ++$this->pos // '+-'
      if ($this->readInt(10) === null) $this->raise(start, "Invalid number")
    }
    if (isIdentifierStart($this->fullCharCodeAtPos())) $this->raise($this->pos, "Identifier directly after number")

    let str = $this->input.slice(start, $this->pos)
    let val = octal ? parseInt(str, 8) : parseFloat(str)
    return $this->finishToken(tt.num, val)
  }

  // Read a string value, interpreting backslash-escapes.

  pp.readCodePoint = function() {
    let ch = $this->input.charCodeAt($this->pos), code

    if (ch === 123) { // '{'
      if ($this->options.ecmaVersion < 6) $this->unexpected()
      let codePos = ++$this->pos
      code = $this->readHexChar($this->input.indexOf("}", $this->pos) - $this->pos)
      ++$this->pos
      if (code > 0x10FFFF) $this->invalidStringToken(codePos, "Code point out of bounds")
    } else {
      code = $this->readHexChar(4)
    }
    return code
  }

  function codePointToString(code) {
    // UTF-16 Decoding
    if (code <= 0xFFFF) return String.fromCharCode(code)
    code -= 0x10000
    return String.fromCharCode((code >> 10) + 0xD800, (code & 1023) + 0xDC00)
  }

  pp.readString = function(quote) {
    let out = "", chunkStart = ++$this->pos
    for (;;) {
      if ($this->pos >= $this->input.length) $this->raise($this->start, "Unterminated string constant")
      let ch = $this->input.charCodeAt($this->pos)
      if (ch === quote) break
      if (ch === 92) { // '\'
        out += $this->input.slice(chunkStart, $this->pos)
        out += $this->readEscapedChar(false)
        chunkStart = $this->pos
      } else {
        if (isNewLine(ch, $this->options.ecmaVersion >= 10)) $this->raise($this->start, "Unterminated string constant")
        ++$this->pos
      }
    }
    out += $this->input.slice(chunkStart, $this->pos++)
    return $this->finishToken(tt.string, out)
  }

  // Reads template string tokens.

  const INVALID_TEMPLATE_ESCAPE_ERROR = {}

  pp.tryReadTemplateToken = function() {
    $this->inTemplateElement = true
    try {
      $this->readTmplToken()
    } catch (err) {
      if (err === INVALID_TEMPLATE_ESCAPE_ERROR) {
        $this->readInvalidTemplateToken()
      } else {
        throw err
      }
    }

    $this->inTemplateElement = false
  }

  pp.invalidStringToken = function(position, message) {
    if ($this->inTemplateElement && $this->options.ecmaVersion >= 9) {
      throw INVALID_TEMPLATE_ESCAPE_ERROR
    } else {
      $this->raise(position, message)
    }
  }

  pp.readTmplToken = function() {
    let out = "", chunkStart = $this->pos
    for (;;) {
      if ($this->pos >= $this->input.length) $this->raise($this->start, "Unterminated template")
      let ch = $this->input.charCodeAt($this->pos)
      if (ch === 96 || ch === 36 && $this->input.charCodeAt($this->pos + 1) === 123) { // '`', '${'
        if ($this->pos === $this->start && ($this->type === tt.template || $this->type === tt.invalidTemplate)) {
          if (ch === 36) {
            $this->pos += 2
            return $this->finishToken(tt.dollarBraceL)
          } else {
            ++$this->pos
            return $this->finishToken(tt.backQuote)
          }
        }
        out += $this->input.slice(chunkStart, $this->pos)
        return $this->finishToken(tt.template, out)
      }
      if (ch === 92) { // '\'
        out += $this->input.slice(chunkStart, $this->pos)
        out += $this->readEscapedChar(true)
        chunkStart = $this->pos
      } else if (isNewLine(ch)) {
        out += $this->input.slice(chunkStart, $this->pos)
        ++$this->pos
        switch (ch) {
        case 13:
          if ($this->input.charCodeAt($this->pos) === 10) ++$this->pos
        case 10:
          out += "\n"
          break
        default:
          out += String.fromCharCode(ch)
          break
        }
        if ($this->options.locations) {
          ++$this->curLine
          $this->lineStart = $this->pos
        }
        chunkStart = $this->pos
      } else {
        ++$this->pos
      }
    }
  }

  // Reads a template token to search for the end, without validating any escape sequences
  pp.readInvalidTemplateToken = function() {
    for (; $this->pos < $this->input.length; $this->pos++) {
      switch ($this->input[$this->pos]) {
      case "\\":
        ++$this->pos
        break

      case "$":
        if ($this->input[$this->pos + 1] !== "{") {
          break
        }
      // falls through

      case "`":
        return $this->finishToken(tt.invalidTemplate, $this->input.slice($this->start, $this->pos))

      // no default
      }
    }
    $this->raise($this->start, "Unterminated template")
  }

  // Used to read escaped characters

  pp.readEscapedChar = function(inTemplate) {
    let ch = $this->input.charCodeAt(++$this->pos)
    ++$this->pos
    switch (ch) {
    case 110: return "\n" // 'n' -> '\n'
    case 114: return "\r" // 'r' -> '\r'
    case 120: return String.fromCharCode($this->readHexChar(2)) // 'x'
    case 117: return codePointToString($this->readCodePoint()) // 'u'
    case 116: return "\t" // 't' -> '\t'
    case 98: return "\b" // 'b' -> '\b'
    case 118: return "\u000b" // 'v' -> '\u000b'
    case 102: return "\f" // 'f' -> '\f'
    case 13: if ($this->input.charCodeAt($this->pos) === 10) ++$this->pos // '\r\n'
    case 10: // ' \n'
      if ($this->options.locations) { $this->lineStart = $this->pos; ++$this->curLine }
      return ""
    default:
      if (ch >= 48 && ch <= 55) {
        let octalStr = $this->input.substr($this->pos - 1, 3).match(/^[0-7]+/)[0]
        let octal = parseInt(octalStr, 8)
        if (octal > 255) {
          octalStr = octalStr.slice(0, -1)
          octal = parseInt(octalStr, 8)
        }
        $this->pos += octalStr.length - 1
        ch = $this->input.charCodeAt($this->pos)
        if ((octalStr !== "0" || ch === 56 || ch === 57) && ($this->strict || inTemplate)) {
          $this->invalidStringToken(
            $this->pos - 1 - octalStr.length,
            inTemplate
              ? "Octal literal in template string"
              : "Octal literal in strict mode"
          )
        }
        return String.fromCharCode(octal)
      }
      return String.fromCharCode(ch)
    }
  }

  // Used to read character escape sequences ('\x', '\u', '\U').

  pp.readHexChar = function(len) {
    let codePos = $this->pos
    let n = $this->readInt(16, len)
    if (n === null) $this->invalidStringToken(codePos, "Bad character escape sequence")
    return n
  }

  // Read an identifier, and return it as a string. Sets `$this->containsEsc`
  // to whether the word contained a '\u' escape.
  //
  // Incrementally adds only escaped chars, adding other chunks as-is
  // as a micro-optimization.

  pp.readWord1 = function() {
    $this->containsEsc = false
    let word = "", first = true, chunkStart = $this->pos
    let astral = $this->options.ecmaVersion >= 6
    while ($this->pos < $this->input.length) {
      let ch = $this->fullCharCodeAtPos()
      if (isIdentifierChar(ch, astral)) {
        $this->pos += ch <= 0xffff ? 1 : 2
      } else if (ch === 92) { // "\"
        $this->containsEsc = true
        word += $this->input.slice(chunkStart, $this->pos)
        let escStart = $this->pos
        if ($this->input.charCodeAt(++$this->pos) !== 117) // "u"
          $this->invalidStringToken($this->pos, "Expecting Unicode escape sequence \\uXXXX")
        ++$this->pos
        let esc = $this->readCodePoint()
        if (!(first ? isIdentifierStart : isIdentifierChar)(esc, astral))
          $this->invalidStringToken(escStart, "Invalid Unicode escape")
        word += codePointToString(esc)
        chunkStart = $this->pos
      } else {
        break
      }
      first = false
    }
    return word + $this->input.slice(chunkStart, $this->pos)
  }

  // Read an identifier or keyword token. Will check for reserved
  // words when necessary.

  pp.readWord = function() {
    let word = $this->readWord1()
    let type = tt.name
    if ($this->keywords.test(word)) {
      if ($this->containsEsc) $this->raiseRecoverable($this->start, "Escape sequence in keyword " + word)
      type = keywordTypes[word]
    }
    return $this->finishToken(type, word)
  }
  
  
  public function inFunction() 
  { 
    return ($this->fnCurrentVarScope()->iFlags & Scope::SCOPE_FUNCTION) > 0;
  }
  
  public function inGenerator() 
  { 
    return ($this->fnCurrentVarScope()->iFlags & Scope::SCOPE_GENERATOR) > 0; 
  }
  public function inAsync() 
  {
    return ($this->fnCurrentVarScope()->iFlags & Scope::SCOPE_ASYNC) > 0;
  }
  
  public function fnInitialContext()
  {
    return [TokenContextTypes::$aTypes['b_stat']];
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

