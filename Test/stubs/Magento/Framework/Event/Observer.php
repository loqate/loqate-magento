<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * The real class extends DataObject and declares getEvent()/setEvent(); only getEvent()
 * is stubbed, since that is all Observer\QuoteSubmitBefore calls. It is declared with no
 * return type (as the real one is) so a test double can return any event-shaped object -
 * the observer only asks it for getQuote().
 */

namespace Magento\Framework\Event;

if (!class_exists(\Magento\Framework\Event\Observer::class, false)) {
    class Observer
    {
        /** @var mixed */
        private $event;

        /**
         * @return mixed
         */
        public function getEvent()
        {
            return $this->event;
        }

        /**
         * @param mixed $event
         * @return $this
         */
        public function setEvent($event)
        {
            $this->event = $event;

            return $this;
        }
    }
}
