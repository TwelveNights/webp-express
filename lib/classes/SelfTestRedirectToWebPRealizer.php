<?php

namespace WebPExpress;

class SelfTestRedirectToWebPRealizer
{

    /**
     * Run test for either jpeg or png
     *
     * @param  array   $config
     * @param  string  $imageType  ("jpeg" or "png")
     * @return array   [$success, $result, $createdTestFiles]
     */
    private static function runTestForImageType($config, $imageType)
    {
        $result = [];
        $createdTestFiles = false;
        $noWarningsYet = true;

        // Copy test image (jpeg)
        list($subResult, $success, $sourceFileName) = SelfTestHelper::copyTestImageToUploadFolder($imageType);
        $result = array_merge($result, $subResult);
        if (!$success) {
            $result[] = 'The test cannot be completed';
            return [false, $result, $createdTestFiles];
        }
        $createdTestFiles = true;

        //$requestUrl = Paths::getUploadUrl() . '/' . $sourceFileName;

        // Hacky, I know.
        // AlterHtmlHelper was not meant to be used like this, but it is the only place where we currently
        // have logic for finding destination url from source url.

        $sourceUrl = Paths::getUploadUrl() . '/' . $sourceFileName;
        AlterHtmlHelper::$options = json_decode(Option::getOption('webp-express-alter-html-options', null), true);
        AlterHtmlHelper::$options['only-for-webps-that-exists'] = false;

        $requestUrl = AlterHtmlHelper::getWebPUrlInBase(
            $sourceUrl,
            'uploads',
            Paths::getUploadUrl(),
            Paths::getAbsDirById('uploads')
        );


        $result[] = '## Lets check that browsers supporting webp gets a freshly converted WEBP ' .
            'when a non-existing WEBP is requested';
        $result[] = 'Making a HTTP request for the test image (pretending to be a client that supports webp, by setting the "Accept" header to "image/webp")';
        $requestArgs = [
            'headers' => [
                'ACCEPT' => 'image/webp'
            ]
        ];
        list($success, $errors, $headers) = SelfTestHelper::remoteGet($requestUrl, $requestArgs);

        if (!$success) {
            $result[count($result) - 1] .= '. FAILED';
            $result = array_merge($result, $errors);
            $result[] = 'The test cannot be completed';
            //$result[count($result) - 1] .= '. FAILED';
            return [false, $result, $createdTestFiles];
        }
        //$result[count($result) - 1] .= '. ok!';
        $result[] = '*' . $requestUrl . '*';

        $result = array_merge($result, SelfTestHelper::printHeaders($headers));

        if (!isset($headers['content-type'])) {
            $result[] = 'Bummer. There is no "content-type" response header. The test FAILED';
            return [false, $result, $createdTestFiles];
        }

        if ($headers['content-type'] == 'image/' . $imageType) {
            $result[] = 'Bummer. As the "content-type" header reveals, we got the ' . $imageType . '.';
            $result[] = 'The test **failed**{: .error}.';
            $result[] = 'Now, what went wrong?';

            if (isset($headers['x-webp-convert-log'])) {
                //$result[] = 'Inspect the "x-webp-convert-log" headers above, and you ' .
                //    'should have your answer (it is probably because you do not have any conversion methods working).';
                if (SelfTestHelper::hasHeaderContaining($headers, 'x-webp-convert-log', 'Performing fail action: original')) {
                    $result[] = 'The answer lies in the "x-convert-log" response headers: ' .
                        '**The conversion failed**{: .error}. ';
                }
            } else {
                $result[] = 'Well, there is indication that the redirection isnt working. ' .
                    'The PHP script should set "x-webp-convert-log" response headers, but there are none. ';
                    'While these headers could have been eaten in a Cloudflare-like setup, the problem is ';
                    'probably that the redirection simply failed';

                    $result[] = '## Diagnosing redirection problems';
                    $result = array_merge($result, SelfTestHelper::diagnoseFailedRewrite($config));
            }
            return [false, $result, $createdTestFiles];
        }

        if ($headers['content-type'] != 'image/webp') {
            $result[] = 'However. As the "content-type" header reveals, we did not get a webp' .
                'Surprisingly we got: "' . $headers['content-type'] . '"';
            $result[] = 'The test FAILED.';
            return [false, $result, $createdTestFiles];
        }

        $result[] = '**Alrighty**{: .ok}. We got a webp.';
        if (isset($headers['x-webp-convert-log'])) {
            $result[] = 'The "x-webp-convert-log" headers reveals we got the webp from the PHP script. **Great!**{: .ok}';
        } else {
            $result[] = 'Interestingly, there are no "x-webp-convert-log" headers even though ' .
                'the PHP script always produces such. Could it be you have some weird setup that eats these headers?';
        }

        if (SelfTestHelper::hasVaryAcceptHeader($headers)) {
            $result[] = 'All is however not super-duper:';

            $result[] = '**Notice: We received a Vary:Accept header. ' .
                'That header need not to be set. Actually, it is a little bit bad for performance ' .
                'as proxies are currently doing a bad job maintaining several caches (in many cases they simply do not)**{: .warn}';
            $noWarningsYet = false;
        }
        if (!SelfTestHelper::hasCacheControlOrExpiresHeader($headers)) {
            $result[] = '**Notice: No cache-control or expires header has been set. ' .
                'It is recommended to do so. Set it nice and big once you are sure the webps have a good quality/compression comprimise.**{: .warn}';
        }
        $result[] = '';

        return [$noWarningsYet, $result, $createdTestFiles];
    }

    private static function doRunTest($config)
    {
        $result = [];
        $result[] = '# Testing redirection to converter';

        $createdTestFiles = false;
        if (!file_exists(Paths::getConfigFileName())) {
            $result[] = 'Hold on. You need to save options before you can run this test. There is no config file yet.';
            return [true, $result, $createdTestFiles];
        }

        if (!$config['enable-redirection-to-webp-realizer']) {
            $result[] = 'Turned off, nothing to test';
            return [true, $result, $createdTestFiles];
        }

        if ($config['image-types'] == 0) {
            $result[] = 'No image types have been activated, nothing to test';
            return [true, $result, $createdTestFiles];
        }

        if ($config['image-types'] & 1) {
            list($success, $subResult, $createdTestFiles) = self::runTestForImageType($config, 'jpeg');
            $result = array_merge($result, $subResult);

            if ($success) {
                if ($config['image-types'] & 2) {
                    $result[] = '## Performing same tests for PNG';
                    list($success, $subResult, $createdTestFiles2) = self::runTestForImageType($config, 'png');
                    $createdTestFiles = $createdTestFiles || $createdTestFiles2;
                    if ($success) {
                        //$result[count($result) - 1] .= '. **ok**{: .ok}';
                        $result[] .= 'All tests passed for PNG as well.';
                        $result[] = '(I shall spare you for the report, which is almost identical to the one above)';
                    } else {
                        $result = array_merge($result, $subResult);
                    }
                }
            }
        } else {
            list($success, $subResult, $createdTestFiles) = self::runTestForImageType($config, 'png');
            $result = array_merge($result, $subResult);
        }

        if ($success) {
            $result[] = '## Conclusion';
            $result[] = 'Everything **seems to work**{: .ok} as it should. ' .
                'However, notice that this test only tested an image which was placed in the *uploads* folder. ' .
                'The rest of the image roots (such as theme images) have not been tested (it is on the TODO). ' .
                'Also on the TODO: If one image type is disabled, check that it does not redirect to the conversion script. ' .
                'These things probably work, though.';
        }


        return [true, $result, $createdTestFiles];
    }

    private static function cleanUpTestImages($config)
    {

        // Clean up test images in upload folder
        SelfTestHelper::deleteTestImagesInUploadFolder();

        // Clean up dummy webp images in cache folder for uploads
        $uploadCacheDir = Paths::getCacheDirForImageRoot(
            $config['destination-folder'],
            $config['destination-structure'],
            'uploads'
        );
        SelfTestHelper::deleteFilesInDir($uploadCacheDir, 'webp-express-test-image-*');

    }

    public static function runTest()
    {
        $config = Config::loadConfigAndFix(false);

        self::cleanUpTestImages($config);

        // Run the actual test
        list($success, $result, $createdTestFiles) = self::doRunTest($config);

        // Clean up test images again. We are very tidy around here
        if ($createdTestFiles) {
            $result[] = 'Deleting test images';
            self::cleanUpTestImages($config);
        }

        return [$success, $result];
    }

}
