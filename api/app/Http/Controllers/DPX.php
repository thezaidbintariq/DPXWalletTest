<?php

namespace App\Http\Controllers;

use App\Http\Controllers\API;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\Hash;
use Web3\Web3;
use GuzzleHttp\Client;
use Tron\Address;
use Carbon\Carbon;
// use Tron\Api;
use Tron\TRX;
class DPX extends Controller
{
    const URI = 'https://nile.trongrid.io';
    public static function GenerateTRXAddress()
    {
        try {
            $api = new \Tron\Api(new Client(['base_uri' => self::URI]));
            $trxWallet = new \Tron\TRX($api);
            $addressData = $trxWallet->generateAddress();
            // $addressData->privateKey
            // $addressData->address

            $config = [
                'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', // USDT TRC20
                'decimals' => 6,
            ];
            $trc20Wallet = new \Tron\TRC20($api, $config);
            $addressData = $trc20Wallet->generateAddress();

        } catch (\Exception $e) {
            return null;
        }
        return ['addressData' => $addressData];
    }

    public static function ValidateTRXAddress(string $address, string $privateKey, string $hexAddress)
    {
        try {
            $api = new \Tron\Api(new Client(['base_uri' => self::URI]));
            $trxWallet = new \Tron\TRX($api);
            $tronAddress = new \Tron\Address($address, $privateKey, $hexAddress);
            $addressData = $trxWallet->validateAddress(
                $tronAddress
            );

        } catch (\Exception $e) {
            return ['addressData' => false];
        }

        return ['addressData' => $addressData];
    }


    public static function CreateWallet(null|string $wallet = null)
    {
    // Generate the wallet if not provided
    if (!($wallet)) {
        $wallet = DPX::GenerateTRXAddress();
    }
    // dd($wallet['addressData']->privateKey);

    // Access the 'addressData' object within the array
    if (isset($wallet['addressData']) && is_object($wallet['addressData'])) {

        $addressData = $wallet['addressData'];
        // Access the properties directly
        $address = $addressData->address;
        $privateKey = $addressData->privateKey;

        // Insert the wallet into the Wallet table
        Wallet::insert([
            'wallet' => $address,
            'secret' => Hash::make($privateKey),
            'hexAddress' => $addressData->hexAddress
        ]);

        // Return the address and private key
        return [
            'wallet' => $address,
            'secret' => $privateKey,
            'hexAddress' => $addressData->hexAddress
        ];
    } else {
        // Handle the case where addressData is not set or not an object
        throw new \Exception('Address data is missing or not an object.');
    }
}


    public static function Transfer(string $departure, string $destination, float $amount, string $secret, float $fee = null)
    {
        try {
            $api = new \Tron\Api(new Client(['base_uri' => self::URI]));
            $trxWallet = new \Tron\TRX($api);

            $from = $trxWallet->privateKeyToAddress($secret);
            $to = new Address(
                $destination,
                '',
                $trxWallet->tron->address2HexString($destination)
            );
            $transferData = $trxWallet->transfer($from, $to, $amount);

            $responseData = [
                "transaction" => $transferData->txID,
                "departure" => $departure,
                "destination" => $destination,
                "amount" => $amount,
                "fee" => $fee ?? "0.2", // Set a default fee if not provided
                "timestamp" => Carbon::createFromTimestampMs($transferData->raw_data['timestamp'])->toDateTimeString(), // Convert to seconds
            ];

            Transaction::insert($responseData);

        } catch (\Exception $e) {
            return API::Error('error', $e->getMessage());
        }

        return API::Respond($responseData ?? []);
    }

    public static function Verify(string $wallet, string $secret, string $hexAddress)
    {

        $validatedAddress = DPX::ValidateTRXAddress($wallet, $secret, $hexAddress);

        if ($validatedAddress['addressData'] === true) {

            return true;
        }

        return false;
    }

    // public static function RevokeSecret(string $wallet, string $secret)
    // {

    //     $wallet = Wallet::where('wallet', $wallet)->first();

    //     if ($wallet && Hash::check($secret, $wallet->secret)) {

    //         $new_secret = DPX::GenerateRandomHash();

    //         Wallet::where(['wallet' => $wallet])->update(['secret' => Hash::make($new_secret)]);

    //         return $new_secret;
    //     }

    //     return false;
    // }


    public static function GetBalance(string $wallet)
    {
        $api = new \Tron\Api(new Client(['base_uri' => self::URI]));
        $trxWallet = new \Tron\TRX($api);

        $address = new Address(
            $wallet,
            '',
            $trxWallet->tron->address2HexString($wallet)
        );

        $balanceData = $trxWallet->balance($address);

        return $balanceData ? API::Respond($balanceData) : API::Error('invalid-wallet', 'Wallet is invalid');
    }

    public static function GetTransaction(string $transaction)
    {

        $transactionInfo = Transaction::where(['transaction' => $transaction])->first(['transaction', 'departure', 'destination', 'amount', 'fee', 'timestamp']);

        return $transactionInfo ? API::Respond($transactionInfo) : API::Error('invalid-transaction', 'Transaction is invalid');
    }

    public static function GetTransactions(int $offset = 0, string|null $departure = null, string|null $destination = null)
    {

        if ($departure && $destination) {

            if ($departure === $destination) {

                $transactions = Transaction::where(['departure' => $departure])
                    ->orWhere(['destination' => $destination])
                    ->orderby('id', 'DESC')
                    ->limit(env('TRANSACTIONS_PER_FETCH', 250))
                    ->offset($offset)
                    ->get(['transaction', 'departure', 'destination', 'amount', 'fee', 'timestamp']);
            } else {

                $transactions = Transaction::where(['departure' => $departure, 'destination' => $destination])
                    ->orderby('id', 'DESC')
                    ->limit(env('TRANSACTIONS_PER_FETCH', 250))
                    ->offset($offset)
                    ->get(['transaction', 'departure', 'destination', 'amount', 'fee', 'timestamp']);
            }
        } else if ($departure) {

            $transactions = Transaction::where(['departure' => $departure])
                ->orderby('id', 'DESC')
                ->limit(env('TRANSACTIONS_PER_FETCH', 250))
                ->offset($offset)
                ->get(['transaction', 'departure', 'destination', 'amount', 'fee', 'timestamp']);
        } else if ($destination) {

            $transactions = Transaction::where(['destination' => $destination])
                ->orderby('id', 'DESC')
                ->limit(env('TRANSACTIONS_PER_FETCH', 250))
                ->offset($offset)
                ->get(['transaction', 'departure', 'destination', 'amount', 'fee', 'timestamp']);
        } else {

            $transactions = Transaction::orderby('id', 'DESC')
                ->limit(env('TRANSACTIONS_PER_FETCH', 250))
                ->offset($offset)
                ->get(['transaction', 'departure', 'destination', 'amount', 'fee', 'timestamp']);
        }

        $transactions = json_decode(json_encode($transactions), true);

        return API::Respond($transactions, json_encode: false);
    }

    // public static function GenerateRandomHash()
    // {

    //     return md5(random_bytes(32) . microtime(true));
    // }
}
