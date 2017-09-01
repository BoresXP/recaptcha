<?php
namespace Evoweb\Recaptcha\Services;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Sebastian Fischer <typo3@evoweb.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Class RecaptchaService
 */
class CaptchaService
{
    /**
     * @var array
     */
    protected $configuration = [];

    /**
     * CaptchaService constructor.
     */
    public function __construct()
    {
        $this->initialize();
    }

    /**
     * @return self
     */
    public static function getInstance()
    {
        /** @var self $instance */
        $instance = GeneralUtility::makeInstance(self::class);
        return $instance;
    }

    /**
     * @throws \Exception
     */
    protected function initialize()
    {
        $configuration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['recaptcha']);

        if (!is_array($configuration)) {
            $configuration = [];
        }

        $frontendController = $this->getTypoScriptFrontendController();
        if (isset($frontendController)
            && $frontendController instanceof TypoScriptFrontendController
            && isset($frontendController->tmpl->setup['plugin.']['tx_recaptcha.'])
            && is_array($frontendController->tmpl->setup['plugin.']['tx_recaptcha.'])
        ) {
            \TYPO3\CMS\Core\Utility\ArrayUtility::mergeRecursiveWithOverrule(
                $configuration,
                $frontendController->tmpl->setup['plugin.']['tx_recaptcha.'],
                true,
                false
            );
        }

        if (!is_array($configuration) || empty($configuration)) {
            throw new \Exception('Please configure plugin.tx_recaptcha. before rendering the recaptcha', 1417680291);
        }

        $this->configuration = $configuration;
    }

    /**
     * @return array
     */
    public function getConfiguration():array
    {
        return $this->configuration;
    }

    /**
     * @return ContentObjectRenderer
     */
    protected function getContentObject()
    {
        /** @var ContentObjectRenderer $contentRenderer */
        $contentRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        return $contentRenderer;
    }

    /**
     * Get development mode by TYPO3_CONTEXT
     * Based on this the captcher does not get rendered or validated
     *
     * @return bool
     */
    protected function isDevelopmentMode():bool
    {
        return (bool) GeneralUtility::getApplicationContext()->isDevelopment();
    }

    /**
     * Get enforcing captcha rendering even if development mode is true
     *
     * @return bool
     */
    protected function isEnforceCaptcha():bool
    {
        return (bool) $this->configuration['enforceCaptcha'];
    }

    /**
     * @return bool
     */
    public function getShowCaptcha():bool
    {
        return !$this->isDevelopmentMode() || $this->isEnforceCaptcha();
    }

    /**
     * Build reCAPTCHA Frontend HTML-Code
     *
     * @return string reCAPTCHA HTML-Code
     */
    public function getReCaptcha():string
    {
        if ($this->getShowCaptcha()) {
            $captcha = $this->getContentObject()->stdWrap(
                $this->configuration['public_key'],
                $this->configuration['public_key.']
            );
        } else {
            $captcha = '<div class="recaptcha-development-mode">
                Development mode active. Do not expect the captcha to appear
                </div>';
        }

        return $captcha;
    }

    /**
     * Validate reCAPTCHA challenge/response
     *
     * @return array Array with verified- (boolean) and error-code (string)
     */
    public function validateReCaptcha():array
    {
        if ($this->isDevelopmentMode() && !$this->isEnforceCaptcha()) {
            return [
                'verified' => true,
                'error' => ''
            ];
        }

        $request = [
            'secret' => $this->configuration['private_key'],
            'response' => trim(GeneralUtility::_GP('g-recaptcha-response')),
            'remoteip' => GeneralUtility::getIndpEnv('REMOTE_ADDR'),
        ];

        $result = ['verified' => false, 'error' => ''];
        if (empty($request['response'])) {
            $result['error'] = 'missing-input-response';
        } else {
            $response = $this->queryVerificationServer($request);
            if (!$response) {
                $result['error'] = 'validation-server-not-responding';
            }

            if ($response['success']) {
                $result['verified'] = true;
            } else {
                $result['error'] = $response['error-codes'];
            }
        }

        return $result;
    }

    /**
     * Query reCAPTCHA server for captcha-verification
     *
     * @param array $data
     *
     * @return array Array with verified- (boolean) and error-code (string)
     */
    protected function queryVerificationServer($data):array
    {
        $verifyServerInfo = @parse_url($this->configuration['verify_server']);

        if (empty($verifyServerInfo)) {
            return [
                'success' => false,
                'error-codes' => 'recaptcha-not-reachable'
            ];
        }

        $request = GeneralUtility::implodeArrayForUrl('', $data);
        $response = GeneralUtility::getUrl($this->configuration['verify_server'] . '?' . $request);

        return json_decode($response, true);
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController()
    {
        return $GLOBALS['TSFE'];
    }
}
