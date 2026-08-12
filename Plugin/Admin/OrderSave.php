<?php

namespace Loqate\ApiIntegration\Plugin\Admin;

use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Controller\Adminhtml\Order\Create\Save;

/**
 * OrderSave class
 */
class OrderSave extends AbstractPlugin
{
    /**
     * Check if address, email and phone number are valid on order create
     *
     * @param Save $subject
     * @param callable $proceed
     * @return Redirect
     */
    public function aroundExecute(Save $subject, callable $proceed)
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return $proceed();
        }

        $request = $subject->getRequest()->getPostValue();
        $error = false;
        $requestAddresses = [];
        if (isset($request['order']['billing_address'])) {
            $requestAddresses['billing_address'] = $request['order']['billing_address'];
        }

        if (isset($request['order']['shipping_address'])) {
            $requestAddresses['shipping_address'] = $request['order']['shipping_address'];
        }

        if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_create_order_admin')) {
            foreach ($requestAddresses as &$requestAddress) {
                if (isset($requestAddress['street']['0'])) {
                    $requestAddress['street_1'] = $requestAddress['street']['0'];
                }
                if (isset($requestAddress['street']['1'])) {
                    $requestAddress['street_2'] = $requestAddress['street']['1'];
                }
            }

            //validate addresses
            $response = $this->validator->verifyMultipleAddresses($requestAddresses, true);
            if (is_array($response)) {
                foreach ($response as $key => $addressResponse) {
                    if (!$addressResponse) {
                        $error = true;
                        $this->messageManager->addErrorMessage(
                            __('The provided address is invalid: ') . '#' . $key
                        );
                    }
                }
            } else {
                $error = true;
                $this->messageManager->addErrorMessage(
                    __('An unexpected error occurred while trying to validate your address.')
                );
            }
        }

        if ($this->helper->getConfigValue('loqate_settings/phone_settings/enable_create_order_admin')) {
            //validate phone numbers for each address
            foreach ($requestAddresses as $key => $address) {
                if (isset($address['telephone'])) {
                    $errorMessage = $this->validatePhone($address['telephone'], $address['country_id']);
                    if ($errorMessage) {
                        $error = true;
                        $this->messageManager->addErrorMessage("#$key: " . $errorMessage);
                    }
                }
            }
        }

        if ($this->helper->getConfigValue('loqate_settings/email_settings/enable_create_order_admin')) {
            //validate email address
            if (isset($request['order']['account']['email'])) {
                $errorMessage = $this->validateEmail($request['order']['account']['email']);
                if ($errorMessage) {
                    $error = true;
                    $this->messageManager->addErrorMessage($errorMessage);
                }
            }
        }

        if ($error) {
            // NO rememberCustomerFormData() HERE, and its absence is the point (LOQ-17149).
            // This branch used to hand the WHOLE order-create POST - the account email address,
            // every address's telephone, the names and the streets - to
            // Magento\Customer\Model\Session::setCustomerFormData(), raw, in the same request
            // that had just stored a one-way digest of that same email address and phone number.
            // It is also the ONLY branch that stores anything, so the leak was on the exact path
            // the two contact stores exist for ("warned once, submit again").
            //
            // NOTHING READ IT BACK, so removing it is a behavioural no-op and not a trade:
            //  - the CORE readers of 'customer_form_data' on the CUSTOMER session are all
            //    storefront (Customer\Block\Form\Register, Customer\Block\Form\Edit,
            //    Customer\Controller\Account\Edit), and all three read it with
            //    getCustomerFormData(true), which clears it on read;
            //  - the adminhtml readers read a DIFFERENT session object, so they never see this
            //    value at all: Customer\Model\Customer\DataProvider (and
            //    DataProviderWithDefaultAddresses) take Framework\Session\SessionManagerInterface,
            //    which is Framework\Session\Generic, and Customer\Block\Adminhtml\Edit\Tab\
            //    Newsletter takes Backend\Model\Session - neither shares the customer session's
            //    storage namespace ('customer', see Customer\Model\Session\Storage). Both belong
            //    to the customer EDIT form in any case, not to order create;
            //  - the redirect below returns to the admin order-create page, which rebuilds every
            //    field from the backend quote session (Backend\Model\Session\Quote), never from
            //    this attribute.
            // So on this path the write was PII residue with no consumer and - because nothing
            // reads it - nothing to clear it either: it sat in the admin's session for the whole
            // of their browser session. The two STOREFRONT callers of
            // AbstractPlugin::rememberCustomerFormData() - Plugin\Frontend\CustomerAccountCreate
            // and CustomerAccountEdit - keep it, because there core really does re-render the
            // form from it and clears it doing so.
            return $this->resultRedirectFactory->create()->setUrl(
                $this->redirect->error($this->redirect->getRefererUrl())
            );
        }

        return $proceed();
    }
}
