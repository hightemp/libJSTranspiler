<?php

namespace libJSTranspiler;

use libJSTranspiler\Node;
use libJSTranspiler\TokenType;
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
      for ($iV = $aOptions['ecmaVersion'];; $iV--)
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
    
  }
  
  public static function fnParse($sInput, $aOptions)
  {
    return (new Parser($sInput, $aOptions))->fnParseI();
  }
  
  public static function fnParseExpressionAt($sInput, $iPos, $aOptions)
  {
    
  }
  
  public static function fnTokenizer($sInput, $aOptions)
  {
    
  }
  
  public static function fnExtend(...$aPlugins)
  {
    
  }
}

