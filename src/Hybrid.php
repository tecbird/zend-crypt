<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Crypt;

use Zend\Math\Rand;
use Zend\Crypt\PublicKey\Rsa\PublicKey as PubKey;
use Zend\Crypt\PublicKey\Rsa\PrivateKey;

/**
 * Hybrid encryption (OpenPGP like)
 *
 * The data are encrypted using a BlockCipher with a random session key
 * that is encrypted using RSA with the public key of the receiver.
 * The decryption process retrieves the session key using RSA with the private
 * key of the receiver and decrypts the data using the BlockCipher.
 */
class Hybrid
{
    /**
     * @var BlockCipher
     */
    protected $bCipher;

    /**
     * @var Rsa
     */
    protected $rsa;

    /**
     * Constructor
     *
     * @param BlockCipher $blockCipher
     * @param Rsa $public
     */
    public function __construct(BlockCipher $bCipher = null, Rsa $rsa = null)
    {
        $this->bCipher = (null === $bCipher ) ? BlockCipher::factory('openssl') : $bCipher;
        $this->rsa     = (null === $rsa ) ? new PublicKey\Rsa() : $rsa;
    }

    /**
     * Encrypt using a keyrings
     *
     * @param string $plaintext
     * @param array|string $keys
     * @return string
     * @throws RuntimeException
     */
    public function encrypt($plaintext, $keys = null)
    {
        // generate a random session key
        $sessionKey = Rand::getBytes($this->bCipher->getCipher()->getKeySize());

        // encrypt the plaintext with blockcipher algorithm
        $this->bCipher->setKey($sessionKey);
        $ciphertext = $this->bCipher->encrypt($plaintext);

        if (! is_array($keys)) {
            $keys = ['' => $keys];
        }

        $encKeys = '';
        // encrypt the session key with public keys
        foreach ($keys as $id => $pubkey) {
            if (! $pubkey instanceof PubKey && ! is_string($pubkey)) {
                throw new Exception\RuntimeException(sprintf(
                    "The public key must be a string in PEM format or an instance of %s",
                    PubKey::class
                ));
            }
            $pubkey = is_string($pubkey) ? new PubKey($pubkey) : $pubkey;
            $encKeys .= sprintf(
                "%s:%s:",
                base64_encode($id),
                base64_encode($this->rsa->encrypt($sessionKey, $pubkey))
            );
        }
        return $encKeys . ';' . $ciphertext;
    }

    /**
     * Decrypt using a private key
     *
     * @param string $msg
     * @param string $privateKey
     * @param string $passPhrase
     * @param string $id
     * @return string
     * @throws RuntimeException
     */
    public function decrypt($msg, $privateKey = null, $passPhrase = null, $id = null)
    {
        // get the session key
        list($encKeys, $ciphertext) = explode(';', $msg, 2);

        $keys = explode(':', $encKeys);
        $pos  = array_search(base64_encode($id), $keys);
        if (false === $pos) {
            throw new Exception\RuntimeException(
                "This private key cannot be used for decryption"
            );
        }

        if (! $privateKey instanceof PrivateKey && ! is_string($privateKey)) {
            throw new Exception\RuntimeException(sprintf(
                "The private key must be a string in PEM format or an instance of %s",
                PrivateKey::class
            ));
        }
        $privateKey = is_string($privateKey) ? new PrivateKey($privateKey, $passPhrase) : $privateKey;
        
        // decrypt the session key with privateKey
        $sessionKey = $this->rsa->decrypt(base64_decode($keys[$pos + 1]), $privateKey);

        // decrypt the plaintext with the blockcipher algorithm
        $this->bCipher->setKey($sessionKey);
        return $this->bCipher->decrypt($ciphertext, $sessionKey);
    }

    /**
     * Get the BlockCipher adapter
     *
     * @return BlockCipher
     */
    public function getBlockCipherInstance()
    {
        return $this->bCipher;
    }

    /**
     * Get the Rsa instance
     *
     * @return Rsa
     */
    public function getRsaInstance()
    {
        return $this->rsa;
    }
}
