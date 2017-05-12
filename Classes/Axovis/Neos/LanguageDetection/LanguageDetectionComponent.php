<?php
namespace Axovis\Neos\LanguageDetection;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Component\ComponentChain;
use Neos\Flow\Http\Component\ComponentContext;
use Neos\Flow\Http\Component\ComponentInterface;
use Neos\Flow\I18n\Detector;
use Neos\Flow\I18n\Locale;
use TYPO3\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
/**
 * A HTTP component that detects the user agent language and redirects to a corresponding section
 */
class LanguageDetectionComponent implements ComponentInterface {
    /**
     * The response which will be returned by this action controller
     * @var \Neos\Flow\Http\Response
     */
    protected $response;

    /**
     * @Flow\Inject
     * @var Detector
     */
    protected $localeDetector;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @var array
     */
    protected $options;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow.http")
     * @var array
     */
    protected $httpSettings;

    /**
     * @Flow\InjectConfiguration(package="MOC.Varnish")
     * @var array
     */
    protected $varnishSettings;

    /**
     * @param array $options The component options
     */
    public function __construct(array $options = array()) {
        $this->options = $options;
    }

    /**
     * @param ComponentContext $componentContext
     * @return void
     */
    public function handle(ComponentContext $componentContext) {
        $httpRequest = $componentContext->getHttpRequest();
        $requestPath = $httpRequest->getUri()->getPath();
        $firstRequestPathSegment = explode('/', ltrim($requestPath, '/'))[0];

        //Check if url contains user, if so, don't detect language
        if(strpos($requestPath,'@user-')){
            return;
        }

        if (isset($this->options['allowedMethods']) && !in_array($httpRequest->getMethod(), $this->options['allowedMethods'])) {
            //the current HTTP method is not within the allow methods, abort!
            return;
        }

        $preset = null;
        if(!isset($this->options['ignoreSegments']) || !in_array($firstRequestPathSegment, $this->options['ignoreSegments'])) {
            $preset = $this->findPreset($firstRequestPathSegment);
            if($preset !== null) {
                //uri contains a valid language segment => no need for us to proceed
                return;
            }
        } else {
            //the configuration told us to ignore this segment => no need for us to proceed
            return;
        }

        $defaultPreset = $this->contentDimensionPresetSource->getDefaultPreset('language');
        $referer = $httpRequest->getHeaders()->get('Referer');
        $refererInfo = $this->parseUriInfo($referer);
        $currentInfo = $this->parseUriInfo((string)$httpRequest->getUri());
        $varnishInfo = isset($this->varnishSettings['varnishUrl']) ? $this->parseUriInfo($this->varnishSettings['varnishUrl']) : null;

        if($refererInfo['host'] == $currentInfo['host'] || ($varnishInfo !== null && $refererInfo['host'] == $varnishInfo['host'])) {
            $firstRefererRequestPathSegment = explode('/', ltrim($refererInfo['requestPath'], '/'))[0];
            $refererPreset = $preset = $this->findPreset($firstRefererRequestPathSegment);

            if(empty($firstRequestPathSegment) && $refererPreset !== null && empty(ltrim(str_replace($firstRefererRequestPathSegment, '', $refererInfo['requestPath']), '/'))) {
                $preset = $defaultPreset;
            } else {
                $preset = $refererPreset;
            }
        } else {
            $detectedLocale = $this->localeDetector->detectLocaleFromHttpHeader($httpRequest->getHeader('Accept-Language'));
            if ($detectedLocale instanceof Locale) {
                $preset = $this->findPreset($detectedLocale->getLanguage());

                if ($preset !== null && empty(trim($requestPath, " \t\n\r\0\x0B/")) && $preset['uriSegment'] == $defaultPreset['uriSegment']) {
                    //we're on the homepage, and the browsers language is equal to the default language => no need for us to proceed
                    return;
                }
            }
        }

        if($preset === null) {
            $preset = $defaultPreset;
        }

        if($preset === null) {
            throw new Exception("Couldn't resolve the language and default language is not set. Check your language config.");
            return;
        }

        $uri = $httpRequest->getUri();
        if(isset($this->httpSettings['baseUri'])) {
            $baseInfo = $this->parseUriInfo($this->httpSettings['baseUri']);

            $uri->setHost($baseInfo['host']);
        }
        if(isset($this->httpSettings['port'])) {
            $uri->setPort($this->httpSettings['port']);
        }
        if(isset($this->httpSettings['username'])) {
            $uri->setUsername($this->httpSettings['username']);
        }
        if(isset($this->httpSettings['password'])) {
            $uri->setUsername($this->httpSettings['password']);
        }
        $uri->setPath('/' . $preset['uriSegment'] . $requestPath);

        $response = $componentContext->getHttpResponse();
        $response->setContent(sprintf('<html><head><meta http-equiv="refresh" content="0;url=%s"/></head></html>', htmlentities((string)$uri, ENT_QUOTES, 'utf-8')));
        $response->setHeader('Location', (string)$uri);

        $componentContext->setParameter(ComponentChain::class, 'cancel', TRUE);
    }

    private function parseUriInfo($url) {
        preg_match('/(([^:]*):\/\/)?(www\.)?([^\/]*)\/?(.*)/',$url, $matches);

        return array(
            'protocol' => $matches[2],
            'host' => $matches[3].$matches[4],
            'requestPath' => $matches[5]
        );
    }

    private function findPreset($needle) {
        return $this->contentDimensionPresetSource->findPresetByUriSegment('language',$needle);
    }
}
