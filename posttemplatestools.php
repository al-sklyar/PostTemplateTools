<?php

namespace Enex\Core\Tools;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Citfact\SiteCore\User\UserRepository;
use Citfact\SiteCore\Core;
use Exception;
use CFile;

Loc::loadMessages(__FILE__);

class PostTemplatesTools
{
    const MERGE_SECTION_LIST = [
        376 => 'ENEX_NEWS', // Новости платформы
        377 => 'MANUFACTURERS_NEWS', // Новости производителя
        378 => 'CUSTOMERS_NEWS', // Новости покупателя
        418 => 'TRADE_COMPANY_NEWS', // Новости торговой компании
    ];

    const MERGE_SECTION_LIST_FUNCITONS = [
        'ENEX_NEWS' => 'getLogoEnex',
        'MANUFACTURERS_NEWS' => 'getLogoManufacturer',
        'CUSTOMERS_NEWS' => 'getLogoBuyer',
        'TRADE_COMPANY_NEWS' => 'getLogoTradeCompany',
    ];

    const MERGE_SECION_LIST_LINKS_FUNCTIONS = [
        'ENEX_NEWS' => 'getDetailEnex',
        'MANUFACTURERS_NEWS' => 'getDetailPageManufacturer',
        'CUSTOMERS_NEWS' => 'getDetailPageBuyer',
        'TRADE_COMPANY_NEWS' => 'getDetailPageTradeCompany',
    ];

    const TEXT_SEARCH_TAGS = ['&lt;pre&gt;', '&lt;/pre&gt;', '&lt;br&gt;'];
    const TEXT_REPLACE_TAGS = ['<pre>', '</pre>', '<br>'];

    private string $enexLogo = '/local/client/img/logo.svg';
    private string $serverName;

    /**
     * @ Добавить дополнительные поля для шаблонов почтовых событий
     *
     * @param string $mailEventType
     * @param array $arPostParams
     * @param array $arOrder
     * @return array
     * @throws Exception
     */
    public function getExtendedPostTemplatesFields(string $mailEventType, array $arPostParams): array
    {
        $protocol = (\CMain::IsHTTPS()) ? "https://" : "http://";
        $this->serverName = $protocol . $_SERVER['SERVER_NAME'];

        switch ($mailEventType) {
            case 'COMPANY_DATA_VALID':
                return $this->getPostTemplateCompanyDataValidFields($arPostParams);
            case 'NEW_NEWS_CREATED':
                return $this->getPostTemplateNewNewsCreatedFields($arPostParams);
            case 'NEW_REVIEW':
                return $this->getPostTemplateNewReviewFields($arPostParams);
            case 'NEW_REVIEW_TO_MANUF':
                return $this->getPostTemplateNewReviewManufFields($arPostParams);
            case 'REPLY_COMMENT':
                return $this->getPostTemplateReplyCommentFields($arPostParams);
            case 'REPLY_COMMENT_TO_MANUF':
                return $this->getPostTemplateReplyCommentToManufFields($arPostParams);
            case 'REQUEST_TO_MANUFACTURER':
            case 'USER_REQUEST_TO_MANUFACTURER':
                return $this->getPostTemplateRequestToManufacturerFields($arPostParams);
            case 'SALE_NEW_ORDER':
                return $this->getPostTemplateSaleNewOrderFields($arPostParams);
            case 'USER_REQUEST_TO_MANUFACTURER_CHANGE':
                return $this->getPostTemplateUserRequestToManufacturerChangeFields($arPostParams);
            case 'NEW_NEWS_SUBSCRIBE':
                return $this->getPostTemplateNewNewsSubscribeFields($arPostParams);
            case 'MANUFACTURER_QUESTION_ANSWER':
                return $this->getPostTemplateManufacturerQuestionAnswerFields($arPostParams);
            default:
                return [];
        }
    }

    /**
     * @Определить значение св-ва "Рубрика" и код типа соответствующего уведомления
     *
     * @param array $elementData
     * @return string
     */
    private function getNotifRubric(array &$elementData): string
    {
        $notifRubric = '';

        if ($rubricEnumId = (int)$elementData['PROPERTY_MERGE_SECTION_ENUM_ID']) {
            // Соответсвие ID варианта списка св-ва "Рубрика" коду типа уведомления
            $notifRubric = self::MERGE_SECTION_LIST[$rubricEnumId];
        }

        return $notifRubric;
    }

    /**
     * @Получить логотип в зависимости от кода рубрики
     *
     * @param array $elementData
     * @return bool|object
     */
    private function getLogo(array &$elementData)
    {
        $code = self::MERGE_SECTION_LIST[$elementData['PROPERTY_MERGE_SECTION_ENUM_ID']];
        $method = self::MERGE_SECTION_LIST_FUNCITONS[$code];

        if (method_exists(self::class, $method)) {
            return $this->$method($elementData);
        }

        return false;
    }

    /**
     * @Получить ссылку на детальную страницу логотипа в зависимости от кода рубрики
     *
     * @param array $elementData
     * @return bool|object
     */
    private function getDetailPage(array &$elementData)
    {
        $code = self::MERGE_SECTION_LIST[$elementData['PROPERTY_MERGE_SECTION_ENUM_ID']];
        $method = self::MERGE_SECION_LIST_LINKS_FUNCTIONS[$code];

        if (method_exists(self::class, $method)) {
            return $this->$method($elementData);
        }

        return false;
    }

    /**
     * @Получить логотип производителя
     *
     * @param array $elementData
     * @return string
     */
    private function getLogoManufacturer(array &$elementData): string
    {
        $userFields = $this->getUserFields($elementData['PROPERTY_AUTHOR_ID_VALUE']);
        $code = $userFields['NAME'];

        if (empty($code)) {
            return false;
        }

        $arOrder = [];
        $arFilter = [
            'IBLOCK_CODE' => \Citfact\SiteCore\Core::IBLOCK_CODE_MANUFACTURES,
            'ACTIVE' => 'Y',
            [
                'LOGIC' => 'OR',
                [
                    'NAME' => $code,
                    'CODE' => mb_strtolower($code)
                ],
            ]
        ];
        $arSelect = [
            'ID',
            'PROPERTY_LOGO',
        ];
        $arGroupBy = false;
        $arNavStartParams = false;

        $glResult = \CIBlockElement::GetList($arOrder, $arFilter, $arGroupBy, $arNavStartParams, $arSelect);
        if ($arItem = $glResult->Fetch()) {
            if (!empty($arItem['PROPERTY_LOGO_VALUE'])) {
                return $this->getResizeImageLink($arItem['PROPERTY_LOGO_VALUE']);
            }
        }

        return false;
    }

    /**
     * @Получить логотип посетителя/покупателя или торговой компании
     *
     * @param array $elementData
     * @param bool $isTradeCompany
     * @return string
     */
    private function getLogoBuyer(array &$elementData, bool $isTradeCompany = false): string
    {
        $code = $elementData['PROPERTY_AUTHOR_ID_VALUE'];

        if (empty($code)) {
            return false;
        }

        $iBlockCode = $isTradeCompany ? \Citfact\SiteCore\Core::IBLOCK_CODE_TRADE_COMPANY_PAGE : \Citfact\SiteCore\Core::IBLOCK_CODE_BUYERS;
        $arOrder = [];
        $arFilter = [
            'IBLOCK_CODE' => $iBlockCode,
            'ACTIVE' => 'Y',
            'PROPERTY_CML2_USER' => $code
        ];
        $arSelect = [
            'ID',
            'PROPERTY_LOGO',
        ];
        $arGroupBy = false;
        $arNavStartParams = false;

        $glResult = \CIBlockElement::GetList($arOrder, $arFilter, $arGroupBy, $arNavStartParams, $arSelect);
        if ($arItem = $glResult->Fetch()) {
            if (!empty($arItem['PROPERTY_LOGO_VALUE'])) {
                return $this->getResizeImageLink($arItem['PROPERTY_LOGO_VALUE']);
            }
        }

        return false;
    }

    /**
     * @Получить логотип торговой компании
     *
     * @param array $elementData
     * @return string
     */
    private function getLogoTradeCompany(array &$elementData): string
    {
        return $this->getLogoBuyer($elementData, true);
    }

    /**
     * @Получить логотип Enex
     *
     * @param array $elementData
     * @return string
     */
    private function getLogoEnex(array &$elementData): string
    {
        return $this->enexLogo;
    }

    /**
     * @Получить ссылку на уменьшенное изображение
     */
    private function getResizeImageLink($imageId)
    {
        $resizeImage = CFile::ResizeImageGet($imageId, ["width" => 103, "height" => 28], BX_RESIZE_IMAGE_PROPORTIONAL, false);

        return self::getSiteUrl() . $resizeImage['src'];;
    }

    /**
     * @Получить детальную ссылку на страницу производителя
     *
     * @param array $elementData
     * @return string
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getDetailPageManufacturer(array &$elementData): string
    {

        $userFields = $this->getUserFields($elementData['PROPERTY_AUTHOR_ID_VALUE']);
        $code = $userFields['NAME'];

        if (empty($code)) {
            return false;
        }

        $result = ElementTable::getList([
            'select' => [
                'DETAIL_PAGE_URL' => 'IBLOCK.DETAIL_PAGE_URL',
                'CODE',
            ],
            'filter' => [
                'LOGIC' => 'OR',
                [
                    '=CODE' => $code,
                ],
                [
                    '=NAME' => $code,
                ]
            ],
        ]);

        if ($arItem = $result->fetch()) {
            $detail = \CIBlock::ReplaceDetailUrl($arItem["DETAIL_PAGE_URL"], $arItem, true, "E");
            return $detail;
        }

        return false;
    }

    /**
     * @Получить ссылку на детальную страницу посетителя/покупателя или торговой компании
     *
     * @param array $elementData
     * @return string
     */
    private function getDetailPageBuyer(array &$elementData, bool $isTradeCompany = false): string
    {
        $code = $elementData['PROPERTY_AUTHOR_ID_VALUE'];

        if (empty($code)) {
            return false;
        }

        $iBlockCode = $isTradeCompany ? \Citfact\SiteCore\Core::IBLOCK_CODE_TRADE_COMPANY_PAGE : \Citfact\SiteCore\Core::IBLOCK_CODE_BUYERS;

        $arOrder = [];
        $arFilter = [
            'IBLOCK_CODE' => $iBlockCode,
            'ACTIVE' => 'Y',
            'PROPERTY_CML2_USER' => $code
        ];
        $arSelect = [
            'ID',
            'DETAIL_PAGE_URL',
            'CODE',
            'NAME',
        ];
        $arGroupBy = false;
        $arNavStartParams = false;

        $glResult = \CIBlockElement::GetList($arOrder, $arFilter, $arGroupBy, $arNavStartParams, $arSelect);
        if ($arItem = $glResult->Fetch()) {
            $detail = \CIBlock::ReplaceDetailUrl($arItem["DETAIL_PAGE_URL"], $arItem, true, "E");
            return $detail;
        }

        return false;

    }

    /**
     * @Получить ссылку на детальную страницу торговой компании
     *
     * @param array $elementData
     * @return string
     */
    private function getDetailPageTradeCompany(array &$elementData): string
    {
        return $this->getLogoBuyer($elementData, true);
    }

    /**
     * @Получить детальную ссылку на страницу Enex
     *
     * @param array $elementData
     * @return string
     */
    private function getDetailEnex(array &$elementData): string
    {
        return 'https://#SERVER_NAME#/';
    }

    /**
     * Метод получает URL сайта (работает и в режиме CLI, и в режиме PHP-FPM)
     *
     * @return string
     */
    public static function getSiteUrl(): string
    {
        $siteUrl = '';
        $context = \Bitrix\Main\Context::getCurrent();
        $request = $context->getRequest();
        $protocol = 'http' . ($request->isHttps() ? 's' : '') . '://';
        $server = $context->getServer();
        if ($serverName = $server->getServerName()) {
            // Это отработает в режиме FPM
            $siteUrl = $protocol . $serverName;
        } elseif ($arSite = \CSite::GetByID(Core::DEFAULT_SITE_ID)->Fetch()) {
            // Это отработает в режиме CLI
            $siteUrl = $protocol . $arSite['SERVER_NAME'];
            if (!empty($arSite['DIR']) && '/' !== $arSite['DIR']) {
                // Для данного сайта назначен какой-то подкаталог.
                // Отрежем последний слэш, если есть
                $str = preg_replace('#/$#', '', $arSite['DIR']);
                $siteUrl .= $str;
            }
        }

        return $siteUrl;
    }

    /**
     * Ограничить длину выводимого текста
     *
     * @param string $text
     *
     * @return string
     */
    private function cropText(string $text): string
    {
        $encoding = 'UTF-8';
        if (!$text
            || mb_strlen($text, $encoding) <= Core::MAX_LENGTH_PREVIEW_TEXT_FOR_EMAIL
        ) {
            return $text;
        }

        $tmp = mb_substr(
            $text,
            0,
            Core::MAX_LENGTH_PREVIEW_TEXT_FOR_EMAIL,
            $encoding
        );

        return mb_substr($tmp, 0, mb_strripos($tmp, ' ', 0, $encoding), $encoding) . '...';
    }

    /**
     * Получить данные пользователя
     *
     * @param $userId
     * @return array|null
     */
    private function getUserFields($userId): ?array
    {
        $isNaturalPerson = false;
        $arUser = \CUser::GetByID($userId)->Fetch();

        $arUserGroups = \CUser::GetUserGroup($userId);
        if (in_array('8', $arUserGroups)) {
            $userGroupRu = 'Клиент, физ. лицо';
            $userGroupEn = 'Client, natural person';
            $isNaturalPerson = true;
        } elseif (in_array('9', $arUserGroups)) {
            $userGroupRu = 'Клиент, юр. лицо';
            $userGroupEn = 'Client, legal person';
        } elseif (in_array('10', $arUserGroups)) {
            $userGroupRu = 'Производитель';
            $userGroupEn = 'Client, manufacturer';
        } else {
            $userGroupRu = '';
            $userGroupEn = '';
        }
        $userCode = $arUser['NAME'];
        if ($isNaturalPerson) {
            $userName = $arUser['UF_NAME'];
        } else {
            $userName = $arUser['WORK_COMPANY'] ?: $arUser['UF_RESPONSIBLE_NAME'];
        }

        unset($isNaturalPerson);

        return [
            'NAME' => $userName,
            'CODE' => $userCode,
            'USER_GROUP' => $userGroupRu,
            'USER_GROUP_EN' => $userGroupEn,
            'DATE_REGISTER' => $arUser['DATE_REGISTER'],
            'INN' => $arUser['UF_INN']
        ];
    }

    /**
     * Получить свойства элемента
     *
     * @param $arFilter
     * @param $arSelect
     * @return array|false
     */
    private function getElementProperties($arFilter, $arSelect)
    {
        return \CIBlockElement::GetList([], $arFilter, false, false, $arSelect)->GetNext();
    }

    /**
     * Получить свойства Highload-блока
     *
     * @param $hlBlockId
     * @param $arFilter
     * @param $arSelect
     * @return false|mixed
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getHlBlockProperties($hlBlockId, $arFilter, $arSelect)
    {
        if (Loader::IncludeModule('highloadblock')) {
            $hldata = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlBlockId)->fetch();
            \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hldata);
            $hlDataClass = $hldata['NAME'] . 'Table';

            return $hlDataClass::getList([
                'select' => $arSelect,
                'filter' => $arFilter,
            ])->fetch();
        } else {
            return false;
        }

    }

    /**
     * Получить название и текст новости в зависимости от языка сайта и при необходимости обрезать текст новости
     *
     * @param $siteId
     * @param $arElProps
     * @param bool $bCrop
     * @return array
     */
    private function getNewsFieldsInSiteLang($arElProps, $bCrop = false): array
    {
        $newsNameRu = $arElProps['PROPERTY_NAME_RU_VALUE'] ?? '';
        $previewTextRu = $arElProps['~PROPERTY_PREVIEW_TEXT_RU_VALUE']['TEXT'] ?? '';

        $newsNameEn = $arElProps['PROPERTY_NAME_EN_VALUE'] ?? '';
        $previewTextEn = $arElProps['~PROPERTY_PREVIEW_TEXT_EN_VALUE']['TEXT'] ?? '';

        if (!$previewTextRu || !$previewTextEn && !empty($arElProps['~PREVIEW_TEXT'])) {
            $previewTextRu = $arElProps['~PREVIEW_TEXT'];
            $previewTextEn = $arElProps['~PREVIEW_TEXT'];
        }

        $previewTextRu = HTMLToTxt($previewTextRu);
        $previewTextEn = HTMLToTxt($previewTextEn);
        if ($bCrop) {
            $previewTextRu = $this->cropText($previewTextRu);
            $previewTextEn = $this->cropText($previewTextEn);
        }

        return [
            'NAME_RU' => $newsNameRu,
            'NAME_EN' => $newsNameEn,
            'TEXT_RU' => $previewTextRu,
            'TEXT_EN' => $previewTextEn
        ];
    }

    /**
     * Получить дополнительные поля для шаблона почтового события "Требуется проверить страницу компании"
     *
     * @param array $arPostParams
     * @return array
     */
    private function getPostTemplateCompanyDataValidFields(array $arPostParams): array
    {
        $iBlockId = $arPostParams['IBLOCK_ID'];
        $elementId = $arPostParams['ELEMENT_ID'];

        $arFilter = ['IBLOCK_ID' => $iBlockId, 'ID' => $elementId];
        $arSelect = ['IBLOCK_ID', 'ID', 'PROPERTY_LOGO', 'CODE'];
        $arElementData = $this->getElementProperties($arFilter, $arSelect);

        $logo = $this->getResizeImageLink($arElementData['PROPERTY_LOGO_VALUE']);
        $userId = $arPostParams['USER_ID'];
        $linkToEdit = self::getSiteUrl() . '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $iBlockId . '&type=manufactures&ID=' . $elementId;
        $linkToSite = self::getSiteUrl() . '/manufacturers/' . $arElementData['CODE'] . '/';

        $arUser = $this->getUserFields($userId);
        $registrationDate = substr($arUser['DATE_REGISTER'], 0, 10);
        $userName = $arUser['NAME'];
        $userGroup = $arUser['USER_GROUP'];

        $arFields = [
            'NAME' => $userName,
            'USER_GROUP' => $userGroup,
            'REGISTRATION_DATE' => $registrationDate,
            'LOGO' => $logo,
            'LINK_TO_EDIT' => $linkToEdit,
            'LINK_TO_SITE' => $linkToSite
        ];

        unset($iBlockId, $elementId, $arFilter, $arSelect, $arElementData, $logo, $userId, $linkToEdit, $linkToSite, $registrationDate, $userGroup, $userName);

        return $arFields;

    }

    /**
     * Получить дополнительные поля для шаблона почтового события "Создана новая новость"
     *
     * @param array $arPostParams
     * @return array
     *
     * @throws Exception
     */
    private function getPostTemplateNewNewsCreatedFields(array $arPostParams): array
    {
        $core = Core::getInstance();

        $userSiteId = $arPostParams['SITE_ID'];
        $iBlockId = $core->getIblockId(Core::IBLOCK_CODE_COMPANY_NEWS);
        $newsId = $arPostParams['NEWS_ID'];
        $authorId = $arPostParams['AUTHOR_ID'];

        $arFilter = ['IBLOCK_ID' => $iBlockId, 'ID' => $newsId];
        $arSelect = ['IBLOCK_ID', 'ID', 'CODE', 'DATE_ACTIVE_FROM', 'PREVIEW_PICTURE', 'PROPERTY_PREVIEW_TEXT_ru', 'PROPERTY_PREVIEW_TEXT_en', 'PROPERTY_NAME_ru', 'PROPERTY_NAME_en'];
        $arElementProperties = $this->getElementProperties($arFilter, $arSelect);

        $pictureUrl = CFile::GetPath($arElementProperties['PREVIEW_PICTURE']);

        $langFields = $this->getNewsFieldsInSiteLang($arElementProperties, true);

        $arUser = $this->getUserFields($authorId);

        $arFilter = ['IBLOCK_ID' => [$core->getIblockId(Core::IBLOCK_CODE_MANUFACTURES), $core->getIblockId(Core::IBLOCK_CODE_BUYERS)], 'NAME' => $arUser['CODE']];
        $arSelect = ['IBLOCK_ID', 'ID', 'CODE', 'PROPERTY_LOGO'];
        $arElementData = $this->getElementProperties($arFilter, $arSelect);

        $logo = $this->getResizeImageLink($arElementData['PROPERTY_LOGO_VALUE']);

        $linkToEdit = self::getSiteUrl() . '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $iBlockId . '&type=content&ID=' . $newsId;
        $linkToSite = self::getSiteUrl() . '/news/' . $arElementProperties['CODE'] . '/';
        $linkToCompanyPage = self::getSiteUrl() . '/manufacturers/' . $arElementData['CODE'] . '/';

        $arFields = [
            'PREVIEW_PICTURE' => $pictureUrl,
            'LOGO' => $logo,
            'DATE_CREATE' => $arElementProperties['DATE_ACTIVE_FROM'],
            'NEWS_NAME_RU' => $langFields['NAME_RU'],
            'NEWS_NAME_EN' => $langFields['NAME_EN'],
            'PREVIEW_TEXT_RU' => $langFields['TEXT_RU'],
            'PREVIEW_TEXT_EN' => $langFields['TEXT_EN'],
            'LINK_TO_EDIT' => $linkToEdit,
            'LINK_TO_SITE' => $linkToSite,
            'LINK_TO_COMPANY_PAGE' => $linkToCompanyPage
        ];

        unset($core, $userSiteId, $authorId, $iBlockId, $arUser, $arFilter, $arSelect, $arElementProperties, $pictureUrl, $langFields, $arElementData, $logo, $linkToEdit, $linkToSite,
            $linkToCompanyPage);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблона почтового события "Добавлен новый отзыв о производителе"
     *
     * @param array $arPostParams
     * @return array
     */
    private function getPostTemplateNewReviewFields(array $arPostParams): array
    {
        $feedbackDate = substr($arPostParams['FEEDBACK_DATE'], 0, 10);
        $previewText = $this->cropText($arPostParams['REVIEW_TEXT'] ?? '');
        $manufacturerId = $arPostParams['MANUFACTURER_ID'];

        $arUserManufacturer = $this->getUserFields($manufacturerId);
        $linkToFeedback = self::getSiteUrl() . '/manufacturers/' . $arUserManufacturer['CODE'] . '/';

        $arUserAuthor = $this->getUserFields($arPostParams['AUTHOR_ID']);
        $authorName = $arUserAuthor['NAME'];
        $authorGroup = $arUserAuthor['USER_GROUP'];
        $authorGroupEn = $arUserAuthor['USER_GROUP_EN'];

        $arFields = [
            'MANUFACTURER_NAME' => $arUserManufacturer['NAME'],
            'AUTHOR_NAME' => $authorName,
            'AUTHOR_GROUP' => $authorGroup,
            'AUTHOR_GROUP_EN' => $authorGroupEn,
            'FEEDBACK_DATE' => $feedbackDate,
            'PREVIEW_TEXT' => $previewText,
            'LINK_TO_FEEDBACK' => $linkToFeedback
        ];

        unset($feedbackDate, $previewText, $manufacturerId, $arUserManufacturer, $linkToFeedback, $arUserAuthor, $authorName, $authorGroup, $authorGroupEn);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблона почтового события "Добавлен новый отзыв о производителе (письмо производителю)"
     *
     * @param array $arPostParams
     * @return array
     *
     * @throws Exception
     */
    private function getPostTemplateNewReviewManufFields(array $arPostParams): array
    {
        $core = Core::getInstance();

        $arFields = $this->getPostTemplateNewReviewFields($arPostParams);

        $manufacturerId = $arPostParams['MANUFACTURER_ID'];
        $reviewId = $arPostParams['REVIEW_ID'];
        $manufacturerEmail = UserRepository::getUserEmail($manufacturerId);
        $linkToEdit = self::getSiteUrl() . '/bitrix/admin/highloadblock_row_edit.php?ENTITY_ID=' . $core->getHlBlockId(Core::HLBLOCK_CODE_REVIEW_MANUFACTURERS) . '&ID=' . $reviewId;

        $arExtraFields = [
            'LINK_TO_EDIT' => $linkToEdit,
            'EMAIL_MANUFACTURER' => $manufacturerEmail
        ];

        $arFields += $arExtraFields;
        unset($core, $reviewId, $manufacturerEmail, $linkToEdit, $arExtraFields);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблонов почтового события "Новый комментарий к товару (письмо менеджеру)"
     *
     * @param array $arPostParams
     * @return array
     *
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws ArgumentException
     * @throws Exception
     */
    private function getPostTemplateReplyCommentFields(array $arPostParams): array
    {
        $core = Core::getInstance();

        $feedbackDate = $arPostParams['FEEDBACK_DATE'];
        $productId = $arPostParams['PRODUCT_ID'];
        $authorId = $arPostParams['AUTHOR_ID'];

        $reviewText = $this->cropText($arPostParams['REVIEW_TEXT'] ?? '');
        $linkToEdit = self::getSiteUrl() . '/bitrix/admin/highloadblock_row_edit.php?ENTITY_ID=' . $core->getHlBlockId(Core::HLBLOCK_CODE_PRODUCT_COMMENTS);
        $logoUrl = '';

        $arUserAuthor = $this->getUserFields($authorId);
        $authorName = $arUserAuthor['NAME'];
        $authorGroup = $arUserAuthor['USER_GROUP'];
        $authorGroupEn = $arUserAuthor['USER_GROUP_EN'];

        $arFilter = ['IBLOCK_ID' => $core->getIblockId($core::IBLOCK_CODE_CATALOG_NEW), 'ID' => $productId];
        $arSelect = ['IBLOCK_ID', 'ID', 'CODE', 'NAME', 'PREVIEW_PICTURE', 'PROPERTY_MANUFACTURER'];
        $arElementProperties = $this->getElementProperties($arFilter, $arSelect);

        $price = '';
        if (!empty($arElementProperties['ID'])
            && ($arPriceData = \CPrice::GetBasePrice($arElementProperties['ID']))
            && !empty($arPriceData['PRICE'])
        ) {
            $price = str_replace('.', ',', $arPriceData['PRICE']);
        }
        $pictureUrl = self::getSiteUrl() . CFile::GetPath($arElementProperties['PREVIEW_PICTURE']);
        $linkToFeedback = self::getSiteUrl() . '/product/' . $arElementProperties['CODE'] . '/';

        $arFilter = ['=UF_XML_ID' => $arElementProperties['PROPERTY_MANUFACTURER_VALUE']];
        $arSelect = ['ID', 'UF_NAME', 'UF_XML_ID'];
        $arHlBlockProperties = $this->getHlBlockProperties($core->getHlBlockId(Core::HLBLOCK_CODE_MANUFACTURERS), $arFilter, $arSelect);

        if ($arHlBlockProperties) {
            $arFilter = ['IBLOCK_ID' => [$core->getIblockId(Core::IBLOCK_CODE_MANUFACTURES), $core->getIblockId(Core::IBLOCK_CODE_BUYERS)], 'NAME' => $arHlBlockProperties['UF_NAME']];
            $arSelect = ['IBLOCK_ID', 'ID', 'NAME', 'PROPERTY_LOGO'];
            $arElProp = $this->getElementProperties($arFilter, $arSelect);
            $logoUrl = self::getSiteUrl() . CFile::GetPath($arElProp['PROPERTY_LOGO_VALUE']);
        }

        $arFields = [
            'PREVIEW_PICTURE' => $pictureUrl,
            'LOGO' => $logoUrl,
            'PRODUCT_NAME' => $arElementProperties['NAME'],
            'PRODUCT_PRICE' => $price,
            'AUTHOR_GROUP' => $authorGroup,
            'AUTHOR_GROUP_EN' => $authorGroupEn,
            'AUTHOR_NAME' => $authorName,
            'FEEDBACK_DATE' => $feedbackDate,
            'PREVIEW_TEXT' => $reviewText,
            'LINK_TO_EDIT' => $linkToEdit,
            'LINK_TO_FEEDBACK' => $linkToFeedback
        ];

        unset($core, $productId, $authorId, $reviewText, $logoUrl, $arUserAuthor, $authorName, $authorGroup, $authorGroupEn, $arFilter, $arSelect, $arElementProperties, $price, $pictureUrl, $linkToFeedback, $arHlBlockProperties, $linkToEdit, $arElProp);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблонов почтового события "Комментарий к товару (письмо производителю)"
     *
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws ArgumentException
     * @throws Exception
     */
    private function getPostTemplateReplyCommentToManufFields(array $arPostParams): array
    {
        $core = Core::getInstance();

        $application = Application::getInstance();
        $request = $application->getContext()->getRequest();
        $postData = $request->getPostList()->toArray();

        $arPostParams['FEEDBACK_DATE'] = $postData['UF_DATE'];
        $arPostParams['REVIEW_TEXT'] = $postData['UF_TEXT'];

        $arFields = $this->getPostTemplateReplyCommentFields($arPostParams);

        unset($core, $application, $request, $postData);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблонов почтовых событий "Обращение к производителю (письмо производителю)" и "Отправка производителю запроса пользователя на доступ к покупке товара в каталоге"
     *
     * @param array $arPostParams
     * @return array
     */
    private function getPostTemplateRequestToManufacturerFields(array $arPostParams): array
    {
        $dateRequest = substr($arPostParams['DATE_REQUEST'], 0, 10);
        $arUser = $this->getUserFields($arPostParams['USER_ID']);

        if ($arUser['INN']) {
            $innTitle = 'ИНН';
            $itnTitle = 'ITN';
        } else {
            $innTitle = '';
            $itnTitle = '';
        }

        $message = '';
        if ($arPostParams['MESSAGE']) {
            $message = $this->cropText($arPostParams['MESSAGE']);
        }

        $arFields = [
            'LINK_TO_PROFILE' => self::getSiteUrl() . '/account/communications/manufacturer/',
            'USER_GROUP' => $arUser['USER_GROUP'],
            'USER_GROUP_EN' => $arUser['USER_GROUP_EN'],
            'USER_NAME' => $arUser['NAME'],
            'DATE_REQUEST' => $dateRequest,
            'INN_TITLE' => $innTitle,
            'ITN_TITLE' => $itnTitle,
            'INN' => $arUser['INN'],
            'MESSAGE' => $message
        ];

        unset($dateRequest, $arUser, $innTitle, $itnTitle, $message);

        return $arFields;
    }


    /**
     * Получить дополнительные поля для шаблона почтового события "Новый заказ"
     *
     * @param array $arPostParams
     * @param $arOrder
     * @return array
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getPostTemplateSaleNewOrderFields(array $arPostParams): array
    {
        $core = Core::getInstance();

        $siteId = $arPostParams['SITE_ID'];
        $accountNumber = $arPostParams['ACCOUNT_NUMBER'];
        $orderRealId = $arPostParams['ORDER_REAL_ID'];
        $orderDate = substr($arPostParams['ORDER_DATE'], 0, 10);
        $location = $arPostParams['LOCATION'];
        $arOrderProperties = $arPostParams['ORDER_PROPERTIES'];
        $deliveryPrice = $arPostParams['PRICE_DELIVERY'];

        $siteId == 's1' ? $lang = 'ru' : $lang = 'en';

        $linkToFile = '';
        $invoiceAttached = '';

        if ($arPostParams['PAY_SYSTEM_ID'] == Core::PAY_SYSTEM_ID_BILL) {
            include_once('include/get_pdf.php');
            $linkToFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/invoices/invoice_' . $accountNumber . '_1_from_' . $orderDate . '.pdf';
            $invoiceAttached = Loc::getMessage('INVOICE_ATTACHED', null, $lang);
        }

        $discount = 0;

        foreach ($arOrderProperties as $arProps) {
            switch ($arProps['CODE']) {
                case 'DISCOUNT_SUM':
                    if ($arProps['VALUE']) {
                        $discount = $arProps['VALUE'];
                    }
                    break;
                case 'UF_DELIVERY_ADDRESS':
                    $pickup = $arProps['VALUE'];
                    break;
                case 'UF_DELIVERY_STREET':
                    $street = $arProps['VALUE'][0];
                    break;
                case 'UF_DELIVERY_HOUSE':
                    $house = $arProps['VALUE'][0];
                    break;
                case 'UF_DELIVERY_FLAT':
                    $flat = $arProps['VALUE'][0];
                    break;
            }
        }

        if ($discount) {
            $discount = '-&nbsp;' . $discount;
        }

        if ($deliveryPrice) {
            $deliveryAddress = $location . ', ' . $street . Loc::getMessage('BLD', null, $lang) . $house;
            $deliveryHeader = Loc::getMessage('DELIVERY_ADDRESS', null, $lang);
            if ($flat) {
                $deliveryAddress = $deliveryAddress . Loc::getMessage('APT', null, $lang) . $flat;
            }
        } else {
            $deliveryAddress = $pickup;
            $deliveryHeader = Loc::getMessage('PICKUP', null, $lang);
            $deliveryPrice = Loc::getMessage('PICKUP', null, $lang);
        }

        $arFilter = ['=UF_ORDER_ID' => $orderRealId];
        $arSelect = ['ID', 'UF_BONUS_COUNT'];
        $arHlBlockProperties = $this->getHlBlockProperties($core->getHlBlockId(Core::HLBLOCK_CODE_ORDER_BONUS_CALCULATED), $arFilter, $arSelect);

        if ($arHlBlockProperties) {
            $bonusPoints = $arHlBlockProperties['UF_BONUS_COUNT'];
        } else {
            $bonusPoints = 0;
        }

        $sTemplate = '';
        $obBasket = \CSaleBasket::GetList([], ['ORDER_ID' => $orderRealId], false, false, ['*']);
        while ($arProps = $obBasket->Fetch()) {
            $arFilter = ['IBLOCK_ID' => $core->getIblockId($core::IBLOCK_CODE_CATALOG_NEW), 'ID' => $arProps['PRODUCT_ID']];
            $arSelect = ['IBLOCK_ID', 'ID', 'PREVIEW_PICTURE', 'PROPERTY_MANUFACTURER'];
            $arProduct = \CIBlockElement::GetList([], $arFilter, false, false, $arSelect)->Fetch();
            $pictureLink = self::getSiteUrl() . CFile::GetPath($arProduct['PREVIEW_PICTURE']);
            $name = $arProps['NAME'];
            $manufacturer = $arProduct['PROPERTY_MANUFACTURER_VALUE'];

            $arFilter = ['=UF_XML_ID' => $manufacturer];
            $arSelect = ['ID', 'UF_NAME', 'UF_XML_ID'];
            $arHlBlockProperties = $this->getHlBlockProperties($core->getHlBlockId(Core::HLBLOCK_CODE_MANUFACTURERS), $arFilter, $arSelect);

            $manufacturerName = '';
            if ($arHlBlockProperties) {
                $manufacturerName = $arHlBlockProperties['UF_NAME'];
            }

            $linkToManufacturer = '#';
            if ($manufacturerName) {
                $arFilter = ['IBLOCK_ID' => $core->getIblockId($core::IBLOCK_CODE_MANUFACTURES), 'NAME' => $manufacturerName];
                $arSelect = ['IBLOCK_ID', 'ID', 'DETAIL_PAGE_URL'];
                $arManufacturer = $this->getElementProperties($arFilter, $arSelect);
                $linkToManufacturer = self::getSiteUrl() . $arManufacturer['DETAIL_PAGE_URL'];
            }

            $quantity = $arProps['QUANTITY'];

            $price = str_replace('.', ',', substr($arProps['PRICE'], 0, -2));

            $sTemplate .= str_replace(
                ['#IMAGE#', '#NAME#', '#LINK_TO_MANUFACTURER#', '#MANUFACTURER#', '#QUANTITY#', '#PRICE#'],
                [$pictureLink, $name, $linkToManufacturer, $manufacturerName, $quantity, $price],
                $this->orderContentTemplate);
        }

        $arFields = [
            'BONUS_POINTS' => $bonusPoints,
            'ORDER_ID' => $accountNumber,
            'DELIVERY_ADDRESS' => $deliveryAddress,
            'DELIVERY_HEADER' => $deliveryHeader,
            'INVOICE_ATTACHED' => $invoiceAttached,
            'ORDER_CONTENT' => $sTemplate,
            'DELIVERY_PRICE' => $deliveryPrice,
            'DISCOUNT' => $discount,
            'LINK_TO_FILE' => $linkToFile
        ];

        unset($core, $siteId, $location, $lang, $orderRealId, $orderDate, $accountNumber, $linkToFile, $deliveryPrice, $discount,
            $pickup, $street, $house, $flat, $deliveryAddress, $deliveryHeader, $invoiceAttached,
            $arFilter, $arSelect, $arHlBlockProperties, $bonusPoints, $sTemplate, $obBasket, $arProduct, $pictureLink, $name,
            $manufacturer, $linkToManufacturer, $arManufacturer, $manufacturerName, $quantity, $price);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблона почтового события "Отправка ответа пользователю на запрос на покупку от производителя"
     *
     * @param array $arPostParams
     * @return array
     *
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws ArgumentException
     * @throws Exception
     */
    private function getPostTemplateUserRequestToManufacturerChangeFields(array $arPostParams): array
    {
        $core = Core::getInstance();

        $approved = $arPostParams['APPROVED'];
        $ticketId = $arPostParams['TICKET_ID'];
        $manufacturerId = $arPostParams['MANUFACTURER_ID'];
        $manufacturer = $arPostParams['MANUFACTURER'];
        $userName = $arPostParams['USER_NAME'];

        $productHtml = '';

        if ($approved) {
            $arFilter = ['ID' => $ticketId];
            $arSelect = ['ID', 'UF_PRODUCT_ID'];
            $arHlBlockProperties = $this->getHlBlockProperties($core->getHlBlockId(Core::HLBLOCK_CODE_MANUFACTURER_REQUEST), $arFilter, $arSelect);
            $productID = $arHlBlockProperties['UF_PRODUCT_ID'];

            $obProduct = \CIblockElement::GetList(
                [],
                ['IBLOCK_ID' => $core->getIblockId($core::IBLOCK_CODE_CATALOG_NEW), 'ID' => $productID],
                false,
                false,
                ['ID', 'NAME', 'PREVIEW_PICTURE', 'PROPERTY_MANUFACTURER', 'DETAIL_PAGE_URL']
            );

            if ($arProduct = $obProduct->GetNext()) {
                $arManufacturer = UserRepository::getManufacturerIBlockDataByUserId($manufacturerId);
                $arProduct['PHOTO'] = CFile::GetPath($arProduct['PREVIEW_PICTURE']);
                $arProduct['PRICES'] = \CCatalogProduct::GetOptimalPrice($arProduct['ID']);
                $arProduct['MANUFACTURER_LOGO'] = CFile::GetPath($arManufacturer['PROPERTIES']['LOGO']['VALUE']);

                ob_start();
                include_once('include/product_in_mail.php');
                $productHtml = ob_get_clean();
            }
        }

        $arFields = [
            "MESSAGE" => $productHtml
        ];

        unset($core, $approved, $ticketId, $manufacturerId, $productHtml, $hlBlock, $dataClass, $productID, $obProduct, $arProduct, $arManufacturer);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблона почтового события "Уведомление подписчикам о создании новой Новости"
     *
     * @param array $arPostParams
     * @return array
     */
    private function getPostTemplateNewNewsSubscribeFields(array $arPostParams): array
    {

        $authorId = $arPostParams['AUTHOR_ID'];
        $userSiteId = $arPostParams['USER_SITE_ID'];
        $elementData = $arPostParams['ELEMENT_DATA'];

        $notifRubric = $this->getNotifRubric($elementData);
        $authorLogo = $this->getLogo($elementData);
        $authorLink = $this->getDetailPage($elementData);


        $authorCompanyName = ($authorId) ? UserRepository::getManufacturerIBlockDataByUserId($authorId)['NAME'] : '';
        $enexCompanyName = ($userSiteId === 's1'
            ? Loc::getMessage('NEWS_SECTION_PLATFORM_COMPANY', null, 'ru')
            : Loc::getMessage('NEWS_SECTION_PLATFORM_COMPANY', null, 'en'));
        $bannerSrc = CFile::GetPath($elementData['PREVIEW_PICTURE']);
        $dateNews = $elementData['ACTIVE_FROM'];

        $langFields = $this->getNewsFieldsInSiteLang($elementData, true);

        $arFields = [
            'BANNER_SRC' => $bannerSrc ?: '',
            'LOGO_SRC' => $authorLogo ?: '',
            'BRAND_DETAIL_PAGE' => $authorLink ?: '',
            'DATE_NEWS' => $dateNews,
            'NEWS_NAME_RU' => $langFields['NAME_RU'],
            'NEWS_NAME_EN' => $langFields['NAME_EN'],
            'PREVIEW_TEXT_NEWS_RU' => $langFields['TEXT_RU'],
            'PREVIEW_TEXT_NEWS_EN' => $langFields['TEXT_EN'],
            'BRAND_NAME' => ($notifRubric !== 'ENEX_NEWS') ? $authorCompanyName : $enexCompanyName,
        ];

        unset($authorId, $userSiteId, $elementData, $notifRubric, $authorLogo, $authorLink, $authorCompanyName, $enexCompanyName, $bannerSrc, $dateNews, $dateNews, $previewText);

        return $arFields;
    }

    /**
     * Получить дополнительные поля для шаблона почтового события "Новый ответ в обращении" (ответ производителя пользователю)
     *
     * @param array $arPostParams
     * @return array
     * @throws Exception
     */
    private function getPostTemplateManufacturerQuestionAnswerFields(array $arPostParams): array
    {
        $core = Core::getInstance();

        $manufacturerId = $arPostParams['MANUFACTURER_ID'];
        $questionId = $arPostParams['QUESTION_ID'];
        $answerId = $arPostParams['ANSWER_ID'];
        $userId = $arPostParams['USER_ID'];

        $arManuf = $this->getUserFields($manufacturerId);

        $arFilter = ['IBLOCK_ID' => [$core->getIblockId(Core::IBLOCK_CODE_MANUFACTURES), $core->getIblockId(Core::IBLOCK_CODE_BUYERS), $core->getIblockId(Core::IBLOCK_CODE_TRADE_COMPANY_PAGE)], 'NAME' => $arManuf['CODE']];
        $arSelect = ['IBLOCK_ID', 'ID', 'CODE', 'PROPERTY_LOGO'];
        $arElementData = $this->getElementProperties($arFilter, $arSelect);

        $logo = $this->getResizeImageLink($arElementData['PROPERTY_LOGO_VALUE']);

        $arUser = $this->getUserFields($userId);

        $message = $this->cropText(HTMLToTxt(str_replace(self::TEXT_SEARCH_TAGS, self::TEXT_REPLACE_TAGS, $arPostParams['MESSAGE'])));

        $linkToSite = self::getSiteUrl() . '/account/communications/manufacturer/ticket/' . $questionId;

        $arFilterHL = ['=ID' => $answerId, '=UF_MFR_QUESTION' => $questionId];
        $arSelectHL = ['ID', 'UF_MFR_QUESTION', 'UF_FILES'];
        $arHlBlockProperties = $this->getHlBlockProperties($core->getHlBlockId(Core::HLBLOCK_CODE_MANUFACTURER_QUESTION_MESSAGES), $arFilterHL, $arSelectHL);

        $arHlBlockProperties['UF_FILES'][0]? $linkToFile = self::getSiteUrl() . CFile::GetPath($arHlBlockProperties['UF_FILES'][0]) : $linkToFile = '';

        $arFields = [
            'USER_NAME' => $arUser['NAME'],
            'MANUFACTURER_NAME' => $arManuf['NAME'],
            'LOGO' => $logo,
            'MESSAGE' => $message,
            'LINK_TO_SITE' => $linkToSite,
            'LINK_TO_FILE' => $linkToFile
        ];

        unset($core, $manufacturerId, $questionId, $userId, $arManuf, $arUser, $arFilter, $arSelect, $arElementData, $logo, $linkToSite, $message, $linkToFile);

        return $arFields;
    }

    private $orderContentTemplate = '
        <!--[if mso | IE]></td><td class="" style="width:580px;" ><![endif]-->
        <div class="mj-column-per-100 mj-outlook-group-fix"
             style="font-size:0;line-height:0;text-align:left;display:inline-block;width:100%;direction:ltr;">
            <!--[if mso | IE]>
            <table border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                    <td style="vertical-align:top;width:52px;"><![endif]-->
            <div class="mj-column-per-9 mj-outlook-group-fix"
                 style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:9%;">
                <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%">
                    <tbody>
                    <tr>
                        <td style="vertical-align:top;padding:0;">
                            <table border="0" cellpadding="0" cellspacing="0" role="presentation" style width="100%">
                                <tbody>
                                <tr>
                                    <td align="center" style="font-size:0px;padding:0;word-break:break-word;">
                                        <table border="0" cellpadding="0" cellspacing="0" role="presentation"
                                               style="border-collapse:collapse;border-spacing:0px;">
                                            <tbody>
                                            <tr>
                                                <td style="width:50px;">
                                                    <a href="#" target="_blank">
                                                        <img height="auto" src="#IMAGE#"
                                                             style="border:0;display:block;outline:none;text-decoration:none;height:auto;width:100%;font-size:13px;"
                                                             width="50">
                                                    </a>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <!--[if mso | IE]></td>
        <td style="vertical-align:top;width:382px;"><![endif]-->
            <div class="mj-column-per-66 mj-outlook-group-fix"
                 style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:66%;">
                <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%">
                    <tbody>
                    <tr>
                        <td style="vertical-align:top;padding:0;padding-right:25px;padding-left:25px;">
                            <table border="0" cellpadding="0" cellspacing="0" role="presentation" style width="100%">
                                <tbody>
                                <tr>
                                    <td align="left" style="font-size:0px;padding:0;padding-bottom:2px;word-break:break-word;">
                                        <div style="font-family:Roboto, Arial, Helvetica, sans-serif;font-size:13px;line-height:20px;text-align:left;color:#002454;">
                                            #NAME#
                                        </div>
        
                                    </td>
                                </tr>
                                <tr>
                                    <td align="left" style="font-size:0px;padding:0;word-break:break-word;">
        
                                        <div style="font-family:Roboto, Arial, Helvetica, sans-serif;font-size:13px;line-height:20px;text-align:left;color:#002454;">
                                            <a href="#LINK_TO_MANUFACTURER#" style="color: #539FE0; text-decoration: none; border: none;">#MANUFACTURER#</a>
                                        </div>
        
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <!--[if mso | IE]></td>
        <td style="vertical-align:top;width:145px;"><![endif]-->
            <div class="mj-column-per-25 mj-outlook-group-fix"
                 style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:25%;">
                <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%">
                    <tbody>
                    <tr>
                        <td style="vertical-align:top;padding:0;padding-top:2px;">
                            <table border="0" cellpadding="0" cellspacing="0" role="presentation" style width="100%">
                                <tbody>
                                <tr>
                                    <td align="right" style="font-size:0px;padding:0;word-break:break-word;">
        
                                        <div style="font-family:Roboto, Arial, Helvetica, sans-serif;font-size:13px;font-weight:500;line-height:24px;text-align:right;color:#002454;">
                                            <span style="line-height: 24px; vertical-align: middle;">#QUANTITY#&nbsp;</span><span
                                                style="color: #9EAABB; font-size: 20px; line-height: 24px; vertical-align: top;">&times;</span><span
                                                style="vertical-align: top;line-height: 24px; ">&nbsp;#PRICE#&nbsp;</span>
                                        </div>
        
                                    </td>
                                </tr>
                                </tbody>
                            </table>
        
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <!--[if mso | IE]></td></tr></table><![endif]-->
        </div>    
        <!--[if mso | IE]></td><td class="" style="vertical-align:top;width:580px;" ><![endif]-->
             <div class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;">
        
      <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%">
        <tbody>
          <tr>
            <td style="vertical-align:top;padding:0;padding-bottom:31px;">
              
      <table border="0" cellpadding="0" cellspacing="0" role="presentation" style width="100%">
        <tbody>
          
        </tbody>
      </table>
    
            </td>
          </tr>
        </tbody>
      </table>
    
      </div>
      <!--[if mso | IE]></td><td class="" style="vertical-align:top;width:580px;" ><![endif]-->
    ';
}