<?php
/**
 * class.mime.php
 *
 * osTicket MIME utilities — extensions for Laminas\Mime components.
 *
 * @author JediKev <kevin@enhancesoft.com>
 * @copyright Copyright (c) osTicket <gpl@osticket.com>
 *
 */
namespace osTicket\Mime {
    use Laminas\Mime\Mime;
    use Laminas\Mime\Part;

    class Rfc2231Part extends Part {
        private ?string $rawfilename = null;

        /**
         * Store the raw (UTF-8) filename for RFC-2231 emission.
         */
        public function setRawFilename(string $name): void {
            $this->rawfilename = $name;
        }

        /**
         * Override to append ; filename*=UTF-8''<percent-encoded> to Content-Disposition.
         * Keep everything else from the parent as-is.
         */
        public function getHeadersArray($eol = Mime::LINEEND): array {
            $headers = parent::getHeadersArray($eol);
            if ($this->rawfilename === null || $this->rawfilename === '')
                return $headers;

            // RFC 2231: UTF-8 then percent-encode (spaces as %20)
            $encoded = rawurlencode($this->rawfilename);

            foreach ($headers as &$h) {
                if (strcasecmp($h[0], 'Content-Disposition') === 0) {
                    $h[1] .= "; filename*=UTF-8''{$encoded}";
                    break;
                }
            }
            unset($h);

            return $headers;
        }
    }
}
