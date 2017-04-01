<?php

/*
 * PHP Encryption Library
 * Copyright (c) 2014, Taylor Hornby
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without 
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, 
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation 
 * and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" 
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE 
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE 
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE 
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR 
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF 
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS 
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN 
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) 
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
 * POSSIBILITY OF SUCH DAMAGE.
 */

/*
 * Web: https://defuse.ca/secure-php-encryption.htm
 * GitHub: https://github.com/defuse/php-encryption 
 *
 * WARNING: This encryption library is not a silver bullet. It only provides
 * symmetric encryption given a uniformly random key. This means you MUST NOT
 * use an ASCII string like a password as the key parameter, it MUST be
 * a uniformly random key generated by CreateNewRandomKey(). If you want to
 * encrypt something with a password, apply a password key derivation function
 * like PBKDF2 or scrypt with a random salt to generate a key.
 *
 * WARNING: Error handling is very important, especially for crypto code! 
 *
 * How to use this code:
 *
 *     Generating a Key
 *     ----------------
 *       try {
 *           $key = self::CreateNewRandomKey();
 *           // WARNING: Do NOT encode $key with bin2hex() or base64_encode(),
 *           // they may leak the key to the attacker through side channels.
 *       } catch (CryptoTestFailedException $ex) {
 *           die('Cannot safely create a key');
 *       } catch (CannotPerformOperationException $ex) {
 *           die('Cannot safely create a key');
 *       }
 *
 *     Encrypting a Message
 *     --------------------
 *       $message = "ATTACK AT DAWN";
 *       try {
 *           $ciphertext = self::Encrypt($message, $key);
 *       } catch (CryptoTestFailedException $ex) {
 *           die('Cannot safely perform encryption');
 *       } catch (CannotPerformOperationException $ex) {
 *           die('Cannot safely perform decryption');
 *       }
 *
 *     Decrypting a Message
 *     --------------------
 *       try {
 *           $decrypted = self::Decrypt($ciphertext, $key);
 *       } catch (InvalidCiphertextException $ex) { // VERY IMPORTANT
 *           // Either:
 *           //   1. The ciphertext was modified by the attacker,
 *           //   2. The key is wrong, or
 *           //   3. $ciphertext is not a valid ciphertext or was corrupted.
 *           // Assume the worst.
 *           die('DANGER! DANGER! The ciphertext has been tampered with!');
 *       } catch (CryptoTestFailedException $ex) {
 *           die('Cannot safely perform encryption');
 *       } catch (CannotPerformOperationException $ex) {
 *           die('Cannot safely perform decryption');
 *       }
 */

/* 
 * Raised by Decrypt() when one of the following conditions are met:
 *  - The key is wrong.
 *  - The ciphertext is invalid or not in the correct format.
 *  - The attacker modified the ciphertext.
 */
class InvalidCiphertextException extends Exception {}
/* If you see these, it means it is NOT SAFE to do encryption on your system. */
class CannotPerformOperationException extends Exception {}
class CryptoTestFailedException extends Exception {}

final class Crypto
{
    // Ciphertext format: [____HMAC____][____IV____][____CIPHERTEXT____].

    /* DO NOT CHANGE THESE CONSTANTS! 
     *
     * We spent *weeks* testing this code, making sure it is as perfect and
     * correct as possible. Are you going to do the same after making your
     * changes? Probably not. Besides, any change to these constants will break
     * the runtime tests, which are extremely important for your security.
     * You're literally millions of times more likely to screw up your own
     * security by changing something here than you are to fall victim to an
     * 128-bit key brute-force attack. You're also breaking your own
     * compatibility with future updates to this library, so you'll be left
     * vulnerable if we ever find a security bug and release a fix.
     *
     * So, PLEASE, do not change these constants.
     */
    const CIPHER = 'aes-128';
    const KEY_BYTE_SIZE = 16;
    const CIPHER_MODE = 'cbc';
    const HASH_FUNCTION = 'sha256';
    const MAC_BYTE_SIZE = 32;
    const ENCRYPTION_INFO = 'DefusePHP|KeyForEncryption';
    const AUTHENTICATION_INFO = 'DefusePHP|KeyForAuthentication';

    /*
     * Use this to generate a random encryption key.
     */
    public static function CreateNewRandomKey()
    {
        self::RuntimeTest();
        return self::SecureRandom(self::KEY_BYTE_SIZE);
    }

    /*
     * Encrypts a message.
     * $plaintext is the message to encrypt.
     * $key is the encryption key, a value generated by CreateNewRandomKey().
     * You MUST catch exceptions thrown by this function. See docs above.
     */
    public static function Encrypt($plaintext, $key)
    {
        self::RuntimeTest();

        if (self::our_strlen($key) !== self::KEY_BYTE_SIZE)
        {
            throw new CannotPerformOperationException("Bad key.");
        }

        $method = self::CIPHER.'-'.self::CIPHER_MODE;
        
        self::EnsureFunctionExists('openssl_get_cipher_methods');
        if (in_array($method, openssl_get_cipher_methods()) === FALSE) {
            throw new CannotPerformOperationException("Cipher method not supported.");
        }
        
        // Generate a sub-key for encryption.
        $keysize = self::KEY_BYTE_SIZE;
        $ekey = self::HKDF(self::HASH_FUNCTION, $key, $keysize, self::ENCRYPTION_INFO);

        // Generate a random initialization vector.
        self::EnsureFunctionExists("openssl_cipher_iv_length");
        $ivsize = openssl_cipher_iv_length($method);
        if ($ivsize === FALSE || $ivsize <= 0) {
            throw new CannotPerformOperationException();
        }
        $iv = self::SecureRandom($ivsize);

        $ciphertext = $iv . self::PlainEncrypt($plaintext, $ekey, $iv);

        // Generate a sub-key for authentication and apply the HMAC.
        $akey = self::HKDF(self::HASH_FUNCTION, $key, self::KEY_BYTE_SIZE, self::AUTHENTICATION_INFO);
        $auth = hash_hmac(self::HASH_FUNCTION, $ciphertext, $akey, true);
        $ciphertext = $auth . $ciphertext;

        return $ciphertext;
    }

    /*
     * Decrypts a ciphertext.
     * $ciphertext is the ciphertext to decrypt.
     * $key is the key that the ciphertext was encrypted with.
     * You MUST catch exceptions thrown by this function. See docs above.
     */
    public static function Decrypt($ciphertext, $key)
    {
        self::RuntimeTest();
        
        $method = self::CIPHER.'-'.self::CIPHER_MODE;
        
        self::EnsureFunctionExists('openssl_get_cipher_methods');
        if (in_array($method, openssl_get_cipher_methods()) === FALSE) {
            throw new CannotPerformOperationException("Cipher method not supported.");
        }

        // Extract the HMAC from the front of the ciphertext.
        if (self::our_strlen($ciphertext) <= self::MAC_BYTE_SIZE) {
            throw new InvalidCiphertextException();
        }
        $hmac = self::our_substr($ciphertext, 0, self::MAC_BYTE_SIZE);
        if ($hmac === FALSE) {
            throw new CannotPerformOperationException();
        }
        $ciphertext = self::our_substr($ciphertext, self::MAC_BYTE_SIZE);
        if ($ciphertext === FALSE) {
            throw new CannotPerformOperationException();
        }

        // Regenerate the same authentication sub-key.
        $akey = self::HKDF(self::HASH_FUNCTION, $key, self::KEY_BYTE_SIZE, self::AUTHENTICATION_INFO);

        if (self::VerifyHMAC($hmac, $ciphertext, $akey))
        {
            // Regenerate the same encryption sub-key.
            $keysize = self::KEY_BYTE_SIZE;
            $ekey = self::HKDF(self::HASH_FUNCTION, $key, $keysize, self::ENCRYPTION_INFO);

            // Extract the initialization vector from the ciphertext.
            self::EnsureFunctionExists("openssl_cipher_iv_length");
            $ivsize = openssl_cipher_iv_length($method);
            if ($ivsize === FALSE || $ivsize <= 0) {
                throw new CannotPerformOperationException();
            }
            if (self::our_strlen($ciphertext) <= $ivsize) {
                throw new InvalidCiphertextException();
            }
            $iv = self::our_substr($ciphertext, 0, $ivsize);
            if ($iv === FALSE) {
                throw new CannotPerformOperationException();
            }
            $ciphertext = self::our_substr($ciphertext, $ivsize);
            if ($ciphertext === FALSE) {
                throw new CannotPerformOperationException();
            }
            
            $plaintext = self::PlainDecrypt($ciphertext, $ekey, $iv);

            return $plaintext;
        }
        else
        {
            /*
             * We throw an exception instead of returning FALSE because we want
             * a script that doesn't handle this condition to CRASH, instead
             * of thinking the ciphertext decrypted to the value FALSE.
             */
             throw new InvalidCiphertextException();
        }
    }

    /*
     * Runs tests.
     * Raises CannotPerformOperationException or CryptoTestFailedException if
     * one of the tests fail. If any tests fails, your system is not capable of
     * performing encryption, so make sure you fail safe in that case.
     */
    public static function RuntimeTest()
    {
        // 0: Tests haven't been run yet.
        // 1: Tests have passed.
        // 2: Tests are running right now.
        // 3: Tests have failed.
        static $test_state = 0;

        if ($test_state === 1 || $test_state === 2) {
            return;
        }

        try {
            $test_state = 2;
            self::AESTestVector();
            self::HMACTestVector();
            self::HKDFTestVector();

            self::TestEncryptDecrypt();
            if (self::our_strlen(self::CreateNewRandomKey()) != self::KEY_BYTE_SIZE) {
                throw new CryptoTestFailedException();
            }

            if (self::ENCRYPTION_INFO == self::AUTHENTICATION_INFO) {
                throw new CryptoTestFailedException();
            }
        } catch (CryptoTestFailedException $ex) {
            // Do this, otherwise it will stay in the "tests are running" state.
            $test_state = 3;
            throw $ex;
        }

        // Change this to '0' make the tests always re-run (for benchmarking).
        $test_state = 1;
    }

    /*
     * Never call this method directly!
     */
    private static function PlainEncrypt($plaintext, $key, $iv)
    {
        
        $method = self::CIPHER.'-'.self::CIPHER_MODE;
        
        self::EnsureConstantExists("OPENSSL_RAW_DATA");
        self::EnsureFunctionExists("openssl_encrypt");
        $ciphertext = openssl_encrypt(
            $plaintext,
            $method,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($ciphertext === false) {
            throw new CannotPerformOperationException();
        }

        return $ciphertext;
    }

    /*
     * Never call this method directly!
     */
    private static function PlainDecrypt($ciphertext, $key, $iv)
    {
        
        $method = self::CIPHER.'-'.self::CIPHER_MODE;
        
        self::EnsureConstantExists("OPENSSL_RAW_DATA");
        self::EnsureFunctionExists("openssl_encrypt");
        $plaintext = openssl_decrypt(
            $ciphertext,
            $method,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($plaintext === FALSE) {
            throw new CannotPerformOperationException();
        }
        
        return $plaintext;
    }

    /*
     * Returns a random binary string of length $octets bytes.
     */
    private static function SecureRandom($octets)
    {
        self::EnsureFunctionExists("openssl_random_pseudo_bytes");
        $random = openssl_random_pseudo_bytes($octets, $crypto_strong);
        if ($crypto_strong === FALSE) {
            throw new CannotPerformOperationException();
        } else {
            return $random;
        }
    }

    /*
     * Use HKDF to derive multiple keys from one.
     * http://tools.ietf.org/html/rfc5869
     */
    private static function HKDF($hash, $ikm, $length, $info = '', $salt = NULL)
    {
        // Find the correct digest length as quickly as we can.
        $digest_length = self::MAC_BYTE_SIZE;
        if ($hash != self::HASH_FUNCTION) {
            $digest_length = self::our_strlen(hash_hmac($hash, '', '', true));
        }

        // Sanity-check the desired output length.
        if (empty($length) || !is_int($length) ||
            $length < 0 || $length > 255 * $digest_length) {
            throw new CannotPerformOperationException();
        }

        // "if [salt] not provided, is set to a string of HashLen zeroes."
        if (is_null($salt)) {
            $salt = str_repeat("\x00", $digest_length);
        }

        // HKDF-Extract:
        // PRK = HMAC-Hash(salt, IKM)
        // The salt is the HMAC key.
        $prk = hash_hmac($hash, $ikm, $salt, true);

        // HKDF-Expand:

        // This check is useless, but it serves as a reminder to the spec.
        if (self::our_strlen($prk) < $digest_length) {
            throw new CannotPerformOperationException();
        }

        // T(0) = ''
        $t = '';
        $last_block = '';
        for ($block_index = 1; self::our_strlen($t) < $length; $block_index++) {
            // T(i) = HMAC-Hash(PRK, T(i-1) | info | 0x??)
            $last_block = hash_hmac(
                $hash,
                $last_block . $info . chr($block_index),
                $prk,
                true
            );
            // T = T(1) | T(2) | T(3) | ... | T(N)
            $t .= $last_block;
        }

        // ORM = first L octets of T
        $orm = self::our_substr($t, 0, $length);
        if ($orm === FALSE) {
            throw new CannotPerformOperationException();
        }
        return $orm;
    }

    private static function VerifyHMAC($correct_hmac, $message, $key)
    {
        $message_hmac = hash_hmac(self::HASH_FUNCTION, $message, $key, true);

        // We can't just compare the strings with '==', since it would make
        // timing attacks possible. We could use the XOR-OR constant-time
        // comparison algorithm, but I'm not sure if that's good enough way up
        // here in an interpreted language. So we use the method of HMACing the 
        // strings we want to compare with a random key, then comparing those.

        // NOTE: This leaks information when the strings are not the same
        // length, but they should always be the same length here. Enforce it:
        if (self::our_strlen($correct_hmac) !== self::our_strlen($message_hmac)) {
            throw new CannotPerformOperationException();
        }

        $blind = self::CreateNewRandomKey();
        $message_compare = hash_hmac(self::HASH_FUNCTION, $message_hmac, $blind);
        $correct_compare = hash_hmac(self::HASH_FUNCTION, $correct_hmac, $blind);
        return $correct_compare === $message_compare;
    }

    private static function TestEncryptDecrypt()
    {
        $key = self::CreateNewRandomKey();
        $data = "EnCrYpT EvErYThInG\x00\x00";

        // Make sure encrypting then decrypting doesn't change the message.
        $ciphertext = self::Encrypt($data, $key);
        try {
            $decrypted = self::Decrypt($ciphertext, $key);
        } catch (InvalidCiphertextException $ex) {
            // It's important to catch this and change it into a 
            // CryptoTestFailedException, otherwise a test failure could trick
            // the user into thinking it's just an invalid ciphertext!
            throw new CryptoTestFailedException();
        }
        if($decrypted !== $data)
        {
            throw new CryptoTestFailedException();
        }

        // Modifying the ciphertext: Appending a string.
        try {
            self::Decrypt($ciphertext . "a", $key);
            throw new CryptoTestFailedException();
        } catch (InvalidCiphertextException $e) { /* expected */ }

        // Modifying the ciphertext: Changing an IV byte.
        try {
            $ciphertext[0] = chr((ord($ciphertext[0]) + 1) % 256);
            self::Decrypt($ciphertext, $key);
            throw new CryptoTestFailedException();
        } catch (InvalidCiphertextException $e) { /* expected */ }

        // Decrypting with the wrong key.
        $key = self::CreateNewRandomKey();
        $data = "abcdef";
        $ciphertext = self::Encrypt($data, $key);
        $wrong_key = self::CreateNewRandomKey();
        try {
            self::Decrypt($ciphertext, $wrong_key);
            throw new CryptoTestFailedException();
        } catch (InvalidCiphertextException $e) { /* expected */ }

        // Ciphertext too small (shorter than HMAC).
        $key = self::CreateNewRandomKey();
        $ciphertext = str_repeat("A", self::MAC_BYTE_SIZE - 1);
        try {
            self::Decrypt($ciphertext, $key);
            throw new CryptoTestFailedException();
        } catch (InvalidCiphertextException $e) { /* expected */ }
    }

    private static function HKDFTestVector()
    {
        // HKDF test vectors from RFC 5869

        // Test Case 1
        $ikm = str_repeat("\x0b", 22);
        $salt = self::hexToBytes("000102030405060708090a0b0c");
        $info = self::hexToBytes("f0f1f2f3f4f5f6f7f8f9");
        $length = 42;
        $okm = self::hexToBytes(
            "3cb25f25faacd57a90434f64d0362f2a" .
            "2d2d0a90cf1a5a4c5db02d56ecc4c5bf" .
            "34007208d5b887185865"
        );
        $computed_okm = self::HKDF("sha256", $ikm, $length, $info, $salt);
        if ($computed_okm !== $okm) {
            throw new CryptoTestFailedException();
        }

        // Test Case 7
        $ikm = str_repeat("\x0c", 22);
        $length = 42;
        $okm = self::hexToBytes(
            "2c91117204d745f3500d636a62f64f0a" .
            "b3bae548aa53d423b0d1f27ebba6f5e5" .
            "673a081d70cce7acfc48"
        );
        $computed_okm = self::HKDF("sha1", $ikm, $length);
        if ($computed_okm !== $okm) {
            throw new CryptoTestFailedException();
        }

    }

    private static function HMACTestVector()
    {
        // HMAC test vector From RFC 4231 (Test Case 1)
        $key = str_repeat("\x0b", 20);
        $data = "Hi There";
        $correct = "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7";
        if (hash_hmac(self::HASH_FUNCTION, $data, $key) != $correct) {
            throw new CryptoTestFailedException();
        }
    }

    private static function AESTestVector()
    {
        // AES CBC mode test vector from NIST SP 800-38A
        $key = self::hexToBytes("2b7e151628aed2a6abf7158809cf4f3c");
        $iv = self::hexToBytes("000102030405060708090a0b0c0d0e0f");
        $plaintext = self::hexToBytes(
            "6bc1bee22e409f96e93d7e117393172a" . 
            "ae2d8a571e03ac9c9eb76fac45af8e51" .
            "30c81c46a35ce411e5fbc1191a0a52ef" .
            "f69f2445df4f9b17ad2b417be66c3710"
        );
        $ciphertext = self::hexToBytes(
            "7649abac8119b246cee98e9b12e9197d" .
            "5086cb9b507219ee95db113a917678b2" .
            "73bed6b8e3c1743b7116e69e22229516" .
            "3ff1caa1681fac09120eca307586e1a7" .
            /* Block due to padding. Not from NIST test vector. 
                Padding Block: 10101010101010101010101010101010
                Ciphertext:    3ff1caa1681fac09120eca307586e1a7
                           (+) 2fe1dab1780fbc19021eda206596f1b7 
                           AES 8cb82807230e1321d3fae00d18cc2012
             
             */
            "8cb82807230e1321d3fae00d18cc2012"
        );

        $computed_ciphertext = self::PlainEncrypt($plaintext, $key, $iv);
        if ($computed_ciphertext !== $ciphertext) {
            throw new CryptoTestFailedException();
        }

        $computed_plaintext = self::PlainDecrypt($ciphertext, $key, $iv);
        if ($computed_plaintext !== $plaintext) {
            throw new CryptoTestFailedException();
        }
    }

    /* WARNING: Do not call this function on secrets. It creates side channels. */
    private static function hexToBytes($hex_string)
    {
        return pack("H*", $hex_string);
    }

    private static function EnsureConstantExists($name)
    {
        if (!defined($name)) {
            throw new CannotPerformOperationException();
        }
    }
    
    private static function EnsureFunctionExists($name)
    {
        if (!function_exists($name)) {
            throw new CannotPerformOperationException();
        }
    }

    /*
     * We need these strlen() and substr() functions because when
     * 'mbstring.func_overload' is set in php.ini, the standard strlen() and
     * substr() are replaced by mb_strlen() and mb_substr().
     */

    private static function our_strlen($str)
    {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($str, '8bit');
            if ($length === FALSE) {
                throw new CannotPerformOperationException();
            }
            return $length;
        } else {
            return strlen($str);
        }
    }

    private static function our_substr($str, $start, $length = NULL)
    {
        if (function_exists('mb_substr'))
        {
            // mb_substr($str, 0, NULL, '8bit') returns an empty string on PHP
            // 5.3, so we have to find the length ourselves.
            if (!isset($length)) {
                if ($start >= 0) {
                    $length = self::our_strlen($str) - $start;
                } else {
                    $length = -$start;
                }
            }

            return mb_substr($str, $start, $length, '8bit');
        }

        // Unlike mb_substr(), substr() doesn't accept NULL for length
        if (isset($length)) {
            return substr($str, $start, $length);
        } else {
            return substr($str, $start);
        }
    }

}

/*
 * We want to catch all uncaught exceptions that come from the Crypto class,
 * since by default, PHP will leak the key in the stack trace from an uncaught
 * exception. This is a really ugly hack, but I think it's justified.
 *
 * Everything up to handler() getting called should be reliable, so this should
 * reliably suppress the stack traces. The rest is just a bonus so that we don't
 * make it impossible to debug other exceptions.
 *
 * This bit of code was adapted from: http://stackoverflow.com/a/7939492
 */

class CryptoExceptionHandler
{
    private $rethrow = NULL;

    public function __construct()
    {
        set_exception_handler(array($this, "handler"));
    }

    public function handler($ex)
    {
        if (
            $ex instanceof InvalidCiphertextException ||
            $ex instanceof CannotPerformOperationException ||
            $ex instanceof CryptoTestFailedException
        ) {
            echo "FATAL ERROR: Uncaught crypto exception. Suppresssing output.\n";
        } else {
            /* Re-throw the exception in the destructor. */
            $this->rethrow = $ex;
        }
    }

    public function __destruct() {
        if ($this->rethrow) {
            throw $this->rethrow;
        }
    }
}

$crypto_exception_handler_object_dont_touch_me = new CryptoExceptionHandler();

