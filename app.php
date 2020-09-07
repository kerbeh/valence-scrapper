<?php

namespace ValenceWrapper;

require_once 'vendor/autoload.php';

use DOMDocument;
use DOMXPath;
use Jawira\CaseConverter\Convert;
use Nette\PhpGenerator\ClassType;

class ApiScrapper
{

    public $baseUri = 'https://docs.valence.desire2learn.com';
    protected $currentModelProperties;

    /**
     * Constructor gets all docuementation routes or a specific supplied route during testing
     */
    public function __construct()
    {
        //Comment out this to get just one route for debugging
        $routeStems = $this->getRouteList();
        //$routeStems = ["res/user.html"];
        foreach ($routeStems as $routeStem) {
            $this->getResourceDocument($routeStem);
        }
    }

    /**
     * Scrape the api docs routing table and get all the unique doc page uri stems.
     * @return Array of route stems
     */
    public function getRouteList()
    {
        $routingTableString = file_get_contents("$this->baseUri/http-routingtable.html");
        $dom = $this->htmlStringToDom($routingTableString);
        $DomXpath = new DOMXPath($dom);
        $nodes = $DomXpath->query("//td/a/@href");

        $uriStemArray = [];
        foreach ($nodes as $node) {

            $uriStemArray[] = explode("#", $node->value)[0];
        }
        return array_values(array_unique($uriStemArray));
    }

    /**
     * Scrape the api resource document for a given route stem
     * @param type $routeStem
     */
    public function getResourceDocument($routeStem)
    {
        $resourceDocsString = file_get_contents("$this->baseUri/$routeStem");

        $dom = $this->htmlStringToDom($resourceDocsString);

        //Get Class Name
        $serviceName = $this->setResourceClassName($dom);
        $serviceDescription = $this->setResourceDescription($dom);

        //$models = $this->getResourceModels($dom);

        $methods = $this->getResourceMethods($dom);

        // var_dump($methods);

        $pageUrl = "$this->baseUri/$routeStem";

        $this->buildMethodClass($methods, $serviceName, $serviceDescription, $pageUrl);

        //foreach (array_filter($models) as $model) {
        //     $this->buildModelClass($model, $serviceName);
        //}
    }

    /**
     * Undocumented function
     *
     * @param [type] $methods
     * @param [type] $serviceName
     * @param [type] $serviceDescription
     * @param [type] $pageUrl
     * @return void
     */
    protected function buildMethodClass($methods, $serviceName, $serviceDescription, $pageUrl)
    {

        var_dump($serviceName);

        $class = new ClassType($serviceName);

        $class
            ->addComment($serviceDescription)
            ->addComment('@see ' . $pageUrl);

        foreach ($methods as $key => $method) {

            //Get api stema and verb
            $methodUrlAndVerb = $this->getApiStemandUrl($method["stem"]);
            //Set the api stem and verb
            $verb = $methodUrlAndVerb["verb"];
            $apiRequest = $methodUrlAndVerb["apiUrl"];

            //Final oputput
            $url = $pageUrl . $method["url"];

            //Join the query parrams and api slug params to create our method parrams
            $requiredQueryParrams = [];
            $requiredParrams = [];
            $requiredjsonParrams = [];
            $queryParramsString = "";
            $queryParrams = [];

            $methodInfo = $method["info"];

            //There should never be more than 1 required json body?
            if (isset($method["jsonParams"][0])) {
                foreach ($method["jsonParams"] as $jsonParams) {

                    $name = new Convert($jsonParams["name"]);

                    $requiredjsonParrams[] = [
                        "name" => $name->toCamel(),
                        "description" => $jsonParams["description"],
                        "type" => $jsonParams["type"],
                    ];
                }
            }

            if (isset($method["parrams"][0])) {
                foreach ($method["parrams"] as $parrams) {
                    $name = new Convert($parrams["name"]);

                    $requiredParrams[] = [
                        "name" => $name->toCamel(),
                        "description" => $parrams["description"],
                        "type" => $parrams["type"],
                    ];
                }
            }

            //If the method has query parrams, put them here
            if (isset($method["query"][0])) {
                foreach ($method["query"] as $query) {

                    $name = new Convert($query["name"]);

                    $requiredQueryParrams[] = [
                        "name" => $name->toCamel(),
                        "description" => $query["description"],
                        "type" => $query["type"],
                    ];

                    $queryParrams[] = $name;

                    $nameCamelCase = $name->toCamel();

                    $queryParramsString .= <<<EOT
                    "$nameCamelCase" => $$nameCamelCase,
EOT;
                };

                $queryParramsString = rtrim($queryParramsString, ',');
                $queryParramsString .= "\n";



                //Build the queryString using the query parrams
                $methodBody = <<<EOT
\$queryParrams = [
$queryParramsString
];
\$queryString = http_build_query(\$queryParrams);
\$uri = "$apiRequest?\$queryString";
EOT;
            } else {
                $methodBody = <<<EOT
\$uri = "$apiRequest";
EOT;
            }

            $methodBodyStandardRequest = "return new Request('GET', \$uri);";

            if (isset($requiredjsonParrams[0])) {

                $jsonBodyName = $requiredjsonParrams[0]["name"];

                $methodBodyJson = <<<EOT
\$body = \$$jsonBodyName;
\$headers = ["content-type" => 'application/json'];
return new Request("PUT", \$uri, \$headers, \$body);
EOT;

                $methodBody .= $methodBodyJson;
            } else {
                $methodBody .= $methodBodyStandardRequest;
            }

            //Get method name
            $methodName = $this->buildMethodNameFromUriEndoint($method["stem"], $requiredParrams);

            //var_dump($methodName);

            $classMethod = $class->addMethod($methodName);
            $methodDescription = $method["description"];

            $classMethod
                ->addComment($methodDescription)
                ->addComment('@see ' . $url)
                ->addComment('@return ' . "/PSR7 (Request)")
                ->setBody($methodBody)
                ->setVisibility('public');

            foreach ($methodInfo as $name => $info) {
                $classMethod->addComment($info . "\n");
            }

            foreach ($requiredParrams as $value) {
                $classMethod->addComment('@param ' . "[" . $value["type"] . "] $" . $value["name"] . " " . $value["description"]);
                $classMethod->addParameter($value["name"]);
            }

            foreach ($requiredjsonParrams as $value) {
                $classMethod->addComment('@param ' . "[" . $value["type"] . "] $" . $value["name"] . " " . $value["description"]);
                $classMethod->addParameter($value["name"]);
            }

            foreach ($requiredQueryParrams as $value) {

                $classMethod->addComment('@param ' . "[" . $value["type"] . "] $" . $value["name"] . " " . $value["description"]);

                if (strpos($value["description"], 'Optional.') !== false) {
                    //  var_dump($value["name"] . "is optional");
                    $classMethod->addComment($value["name"]);
                    $classMethod->addParameter($value["name"], null);
                } else {
                    // var_dump($value["name"] . " is NOT optional");
                    $classMethod->addParameter($value["name"]);
                }
            }
        }

        if (isset($serviceName)) {
            file_put_contents("Service/" . $serviceName . ".php", "<?php\nnamespace ValenceWrapper\Service;\nuse GuzzleHttp\Psr7\Request;\n" . $class);
        }
    }

    protected function getApiStemandUrl($stem)
    {

        //Build the api url
        preg_match_all("/\((.*?)\)/", $stem, $variables);

        $variableString = $stem;
        $allVariables = array_combine($variables[0], $variables[1]);
        foreach ($allVariables as $find => $replace) {
            $variableString = str_replace($find, "$" . "$replace", $variableString);
        }

        $variableStringNoVerb = explode("--", $variableString);

        $verb = strtoupper($variableStringNoVerb[0]);
        $apiRequest = "/" . str_replace("-", "/", $variableStringNoVerb[1]);

        return ["verb" => $verb, "apiUrl" => $apiRequest];
    }

    //Method names need to be unique, chagning this will result in breaking changes
    //!!!!!!!!!!!!!!!
    protected function buildMethodNameFromUriEndoint($stem, $requiredParrams)
    {

        //Build the method name
        $stemWithoutPlaceholder = preg_replace("/\(\w*\)/", "", $stem);
        $stemWithoutapi = preg_replace("/d2l-api-\w*/", "", $stemWithoutPlaceholder);

        $cleanedString = str_replace("--", "-", str_replace("----", "-", $stemWithoutapi));

        $methodName = new Convert($cleanedString);

        $filteredParrams = array_filter($requiredParrams, function ($parram) {
            return ($parram["name"] !== "version");
        });
        $nameSuffix = "";
        foreach ($filteredParrams as $param) {
            $nameSuffix .= ucfirst($param["name"]);
        }

        $methodName = $methodName->toCamel() . $nameSuffix;
        return $methodName;
    }

    /**
     * MODEL CLASS IS HERE
     *
     * @param [type] $model
     * @param [type] $serviceName
     * @return void
     */
    protected function buildModelClass($model, $serviceName)
    {

        $className = $model['ModelClassName'];
        $class = new ClassType($className);

        $class
            ->addComment($model["ModelClassDescription"])
            ->addComment('@see ' . $this->baseUri . $model["ModelClassLinktoDocs"]);

        foreach ($model["ModelProperties"] as $name => $type) {

            $propName = new Convert($name);
            $class
                ->addProperty($propName->toPascal())
                ->setVisibility('private')
                ->addComment('@param ' . $name)
                ->addComment('@type ' . $type);

            if (isset($model["ModelPropertyComments"][$name])) {
                $class->addComment($model["ModelPropertyComments"][$name]);
            }
        }

        $string = "";
        foreach ($model["ModelProperties"] as $name => $type) {
            $string .= '$this->' . $name . ' = $attributes["' . $name . '"];' . PHP_EOL;
        }

        $method = $class->addMethod('__construct')
            ->addComment('Constructor for ' . $className)
            ->setVisibility('public')
            ->setBody("$string");

        $method->addParameter('attributes', []) // $items = []
            //   ->setReference() // &$items = []
            ->setTypeHint('array'); // array &$items = []
        //   echo $class;
        if (!file_exists("Model/" . $serviceName)) {
            mkdir("Model/" . $serviceName);
        }
        $className = $model['ModelClassName'];
        if (isset($model['ModelClassName'])) {
            file_put_contents("Model/" . $serviceName . "/" . $className . ".php", "<?php\nnamespace ValenceWrapper\Model\\$className;\nuse ValenceWrapper\Model\BaseValenceModel;\nuse ValenceWrapper\Model\Basic\UtcDateTime;\nuse ValenceWrapper\Model\Basic\RichText;\n" . $class);
        }
    }

    /**
     * Undocumented function
     *
     * @param DOMDocument $dom
     * @return void
     */
    protected function getResourceMethods(DOMDocument $dom)
    {

        $DomXpath = new DOMXPath($dom);
        $nodes = $DomXpath->query("//div[@id='actions']//*[@class='get' or @class='post' or @class='put' or @class='delete']");

        $methods = [];

        foreach ($nodes as $node) {

            //div[@id='actions']//*[@class='get' or @class='post' or @class='put' or @class='delete']/dd/dl[contains(@class, 'field-list')]

            $methodUrl = $DomXpath->query(".//*[contains(@class, 'headerlink')]", $node)->item(0)->getAttribute("href");
            $methodDescription = $DomXpath->query("./dd//p", $node)->item(0)->textContent;
            $methodStem = $DomXpath->query("./dt", $node)->item(0)->getAttribute("id");
            $methodQueryStringNodes = $DomXpath->query("./dd/dl[contains(@class, 'field-list')]", $node)->item(0);
            $methodNotesInputReturn = $DomXpath->query("./dd/p", $node);

            //Build the comments form the definaiiton list
            $types = $DomXpath->query(".//dt", $methodQueryStringNodes);
            $definitions = $DomXpath->query(".//dd", $methodQueryStringNodes);

            $comments = [];
            foreach ($types as $key => $type) {
                $comments[$type->textContent] = $definitions[$key]->textContent;
            }
            $methodQueryString = null;
            if (isset($comments["Query Parameters"])) {
                $methodQueryString = $this->getParrams($comments["Query Parameters"]);
            }
            $paramters = [];
            if (isset($comments["Parameters"])) {
                $paramters = $this->getParrams($comments["Parameters"]);
            }
            //Get all of the parameters of type json
            $jsonParams = [];
            if (isset($comments["JSON Parameters"])) {
                $jsonParams = $this->getParrams($comments["JSON Parameters"]);
            }

            $methods[] = [
                "url" => $methodUrl,
                "description" => $methodDescription,
                "stem" => $methodStem,
                "query" => $methodQueryString,
                "parrams" => $paramters,
                "jsonParams" => $jsonParams,
                "info" => $this->filterethodNotesInputReturn($methodNotesInputReturn),
            ];
        }
        return $methods;
    }

    protected function filterethodNotesInputReturn($nodes)
    {

        $methodInfo = [];

        foreach ($nodes as $node) {

            if (substr($node->textContent, 0, 6) === "Return") {
                $methodInfo["return"] = $node->textContent;
            } elseif (substr($node->textContent, 0, 5) === "Input") {
                $methodInfo["input"] = $node->textContent;
            } elseif (substr($node->textContent, 0, 5) === "Note") {
                $methodInfo["note"] = $node->textContent;
            }
        }

        return $methodInfo;
    }

    protected function getParrams($queryParramString)
    {
        $queryParrams = [];

        /* This regex matches the definaition lists of the parrams
        i.e:
        CreateUserData (User.CreateUserData) – Data for new user.
        version (D2LVERSION) – API version.
        size (integer) – Optional. Desired thumbnail size (height and width in pixels) for profile image.
        MapIdentifier (MapIdentifier) – Global user map identifier.
         */
        preg_match_all(
            '/(\w.*?) \(([\w\. ]*?)\) \– (\w.*)/',
            $queryParramString,
            $matches
        );

        foreach ($matches[0] as $key => $match) {
            $queryParrams[] = [
                "name" => $matches[1][$key],
                "type" => $matches[2][$key],
                "description" => $matches[3][$key],
            ];
        }
        return $queryParrams;
    }

    protected function getResourceModels(DOMDocument $dom)
    {

        $DomXpath = new DOMXPath($dom);
        $nodes = $DomXpath->query("//div[@id='attributes']/*");

        foreach ($nodes as $node) {

            //Skip the header as its mixed in with the child nodes.
            if ($node->tagName == "h1") {
                continue;
            }

            if (
                null !== ($modelResourceName = $DomXpath->query(".//code[contains(@class, 'descclassname')]", $node))->item(0)
            ) {
                $modelResourceName = $DomXpath->query(".//code[contains(@class, 'descclassname')]", $node)->item(0)->textContent;
            }
            if (
                null !== ($modelClassName = $DomXpath->query(".//*[contains(@class, 'descname')]", $node)->item(0))
            ) {
                $modelClassName = $DomXpath->query(".//*[contains(@class, 'descname')]", $node)->item(0)->textContent;
            }

            if (
                null !== $DomXpath->query(".//*[contains(@class, 'headerlink')]", $node)->item(0)
            ) {
                $modelClassLinktoDocs = $DomXpath->query(".//*[contains(@class, 'headerlink')]", $node)->item(0)->getAttribute("href");
            }
            $modelClassDescription = (isset($DomXpath->query(".//p", $node)->item(0)->textContent)) ? $DomXpath->query(".//p", $node)->item(0)->textContent : "";
            $modelProperties = $DomXpath->query(".//*[contains(@class, 'highlight-d2ljson')]", $node)->item(0)->textContent;
            $modelPropertyComments = (isset($DomXpath->query(".//*/dl[contains(@class, 'simple')]", $node)->item(0)->textContent)) ? $DomXpath->query(".//*/dl", $node)->item(0) : null;

            if (isset($modelPropertyComments)) {
                $modelPropertyCommentDefinitions = $this->getDefinitionList($DomXpath, $modelPropertyComments);
            } else {
                $modelPropertyCommentDefinitions = [];
            }

            $this->currentModelProperties = $modelProperties;

            //Get child objects
            $objects = $this->getChildObjects();

            //Get the composite types expected in an array
            $arrays = $this->getChildArray();

            $keyPairs = $this->getKeyPairs($this->currentModelProperties);

            $modelProperties = array_merge(
                $objects,
                //  $arrays,
                $keyPairs
            );

            $attributes[] = [
                "ModelResourceName" => $modelResourceName,
                "ModelClassName" => $modelClassName,
                "ModelClassLinktoDocs" => $modelClassLinktoDocs,
                "ModelClassDescription" => $modelClassDescription,
                "ModelProperties" => $modelProperties,
                "ModelPropertyComments" => $modelPropertyCommentDefinitions,
            ];
        }

        return $attributes;
    }

    /**
     * search the dom for definaiiton lists
     *
     * @param [type] $DomXpath
     * @param [type] $nodeList
     * @return void
     */
    protected function getDefinitionList($DomXpath, $nodeList)
    {

        $types = $DomXpath->query(".//dt", $nodeList);
        $definitions = $DomXpath->query(".//dd", $nodeList);

        $comments = [];

        foreach ($definitions as $key => $definition) {
            $definitionName = new Convert($types[$key]->textContent);

            $comments[$definitionName->toPascal()] = $definitions[$key]->textContent;
        }

        return $comments;
    }

    protected function getKeyPairs($string)
    {
        preg_match_all('/\"([:><\w]*)\"[:]\s([:>{} <\w\.]*)[,]?/', $string, $matchedKeyPairs);

        $keypairs = array_combine($matchedKeyPairs[1], $matchedKeyPairs[2]);

        return $keypairs;
    }

    protected function getChildObjects()
    {

        //Get child object
        preg_match_all('/\"(\w*)\":\s{([\s].*?)}[,]?/s', $this->currentModelProperties, $matches);

        $objects = [];

        //Loop over each match and assign based on the capture group
        //Remove the aptured object from the string
        foreach ($matches[0] as $matchKey => $match) {

            $keypairs = $this->getKeyPairs($match);

            $objects = $keypairs;

            //      $this->currentModelProperties = str_replace($matches[0][$matchKey], "", $this->currentModelProperties);
        }

        return $objects;
    }

    /**
     * Find the composite strcuture expected in the array
     *
     * @return void
     */
    protected function getChildArray()
    {
        //Get child object
        preg_match_all('/\"(\w*)\":\s\[([\s].*?)\][,]?/s', $this->currentModelProperties, $matches);

        $objects = [];

        //Loop over each match and assign based on the capture group
        //Remove the aptured object from the string
        foreach ($matches[0] as $matchKey => $match) {

            $objects[] = ["compositeType" => $matches[1][$matchKey]];

            $this->currentModelProperties = str_replace($matches[0][$matchKey], "", $this->currentModelProperties);
        }

        return $objects;
    }

    /**
     * Set the pascal case Service Class name based on the resource document page title
     * @param DOMDocument $dom
     */
    protected function setResourceClassName(DOMDocument $dom)
    {

        $DomXpath = new DOMXPath($dom);
        $nodes = $DomXpath->query("//title");

        preg_match('/^[a-zA-Z0-9\s]*/', $nodes->item(0)->textContent, $matches);

        $serviceName = new Convert($matches[0]);

        return $serviceName->toPascal();
    }

    protected function setResourceDescription(DOMDocument $dom)
    {

        $DomXpath = new DOMXPath($dom);
        $nodes = $DomXpath->query("//title");

        preg_match('/^[a-zA-Z0-9\s]*/', $nodes->item(0)->textContent, $matches);

        return $nodes->item(0)->textContent;
    }

    protected function htmlStringToDom($htmlString)
    {
        //Create a DOM out of the string, while supressing doc type errors
        //@see https://bugs.php.net/bug.php?id=60021
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlString);
        libxml_clear_errors();

        return $dom;
    }

    /**
     * Translates a string with underscores
     * into camel case (e.g. first_name -> firstName)
     *
     * @param string $str String in underscore format
     * @param bool $capitalise_first_char If true, capitalise the first char in $str
     * @return string $str translated into camel caps
     * @see https://gist.github.com/paulferrett/8141290
     */
    protected function to_camel_case($str, $capitalise_first_char = false)
    {
        if ($capitalise_first_char) {
            $str[0] = strtoupper($str[0]);
        }
        $func = create_function('$c', 'return strtoupper($c[1]);');
        return preg_replace_callback('/_([a-z])/', $func, $str);
    }
}

$scrapper = new ApiScrapper();
