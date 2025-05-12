<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\BloodRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BloodSearchController extends Controller
{
    public function index()
    {
        return view('search_blood');
    }

    public function findNearbyAdmins(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric'
        ]);

        $admins = Admin::select(['id', 'name', 'latitude', 'longitude'])->get();

        $adminsWithDistance = $admins->map(function ($admin) use ($request) {
            $admin->distance = $this->calculateDistance(
                $request->latitude,
                $request->longitude,
                $admin->latitude,
                $admin->longitude
            );
            return $admin;
        });

        return response()->json($adminsWithDistance->sortBy('distance')->values());
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }

    public function submitRequest(Request $request)
    {
        $request->validate([
            'admin_id' => 'required|exists:admins,id',
            'blood_group' => 'required|in:A+,A-,B+,B-,O+,O-,AB+,AB-',
            'blood_quantity' => 'required|integer|min:1|max:5',
            'request_type' => [
                'required',
                Rule::in(['Emergency', 'Normal', 'Rare']),
                function ($attribute, $value, $fail) use ($request) {
                    $rareBloodTypes = ['AB-', 'B-', 'A-'];
                    if ($value === 'Rare' && !in_array($request->blood_group, $rareBloodTypes)) {
                        $fail('Rare request type can only be selected for rare blood types (AB-, B-, A-)');
                    }
                }
            ],
            'request_form' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'payment' => 'required|numeric|min:0|max:1500',
        ]);

        $user = Auth::user();
        $imageName = time().'.'.$request->file('request_form')->getClientOriginalExtension();
        $request->file('request_form')->move(public_path('assets/request_forms'), $imageName);
        $imagePath = 'assets/request_forms/'.$imageName;

        // Store data in session (not DB yet)


        $request->session()->put('blood_request', [
            'id' => $request->id,
            'user_id' => $user->id,
            'admin_id' => $request->admin_id,
            'user_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'blood_group' => $request->blood_group,
            'blood_quantity' => $request->blood_quantity,
            'request_type' => $request->request_type,
            'request_form' => $imagePath,
            'payment' => $request->payment,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request prepared, proceed to payment.',
            'redirect_url' => route('payment.page'),
        ]);
    }

    public function showPaymentPage(Request $request)
    {
        $bloodRequest = $request->session()->get('blood_request');

        if (!$bloodRequest) {
            return redirect()->route('search_blood')->with('error', 'No blood request found to process payment');
        }

        return view('receiver.payment', [
            'bloodRequest' => $bloodRequest,
            'amount' => $bloodRequest['payment'],
            'blood_quantity' => $bloodRequest['blood_quantity']
        ]);
    }

    public function processPayment(Request $request)
    {
        $validated = $request->validate([
            'blood_quantity' => 'required|integer|min:1|max:5',
            'payment' => ['required','numeric','min:0','max:1500',
                function($attribute, $value, $fail) use ($request) {
                    $expected = $request->blood_quantity * 500;
                    if ($value != $expected) {
                        $fail('Invalid payment amount calculated');
                    }
                }
            ],
        ]);

        // Get current session data
        $bloodRequest = $request->session()->get('blood_request');

        // Update only the necessary fields
        $bloodRequest['blood_quantity'] = $validated['blood_quantity'];
        $bloodRequest['payment'] = $validated['payment'];

        // Save back to session and persist immediately
        $request->session()->put('blood_request', $bloodRequest);
        $request->session()->save(); // Force immediate save

        // Debug the updated values
        \Illuminate\Support\Facades\Log::debug('Updated Payment Data', [
            'blood_quantity' => $bloodRequest['blood_quantity'],
            'payment' => $bloodRequest['payment']
        ]);

        return redirect()->route('process.esewa.payment');
    }

    public function processEsewaPayment(Request $request)
    {
        // Get fresh session data
        $bloodRequest = $request->session()->get('blood_request');

        if (!$bloodRequest) {
            return redirect()->route('search_blood')->with('error', 'No blood request found');
        }

        // Debug the values being used
        \Illuminate\Support\Facades\Log::debug('Processing eSewa Payment With', [
            'blood_quantity' => $bloodRequest['blood_quantity'],
            'payment' => $bloodRequest['payment']
        ]);

        $transaction_uuid = uniqid('txn_') . time();
        $secret_key = "8gBm/:&EnhH.1/q";
        $product_code = "EPAYTEST";

        // Use the UPDATED payment amount from session
        $signature_string = "total_amount={$bloodRequest['payment']},transaction_uuid={$transaction_uuid},product_code={$product_code}";
        $signature = base64_encode(hash_hmac('sha256', $signature_string, $secret_key, true));

        return view('receiver.esewa_payment', [
            'amount' => $bloodRequest['payment'],
            'tax_amount' => 0,
            'total_amount' => $bloodRequest['payment'],
            'transaction_uuid' => $transaction_uuid,
            'product_code' => $product_code,
            'success_url' => route('payment.success'),
            'failure_url' => route('payment.failure'),
            'signature' => $signature,
            'signed_field_names' => 'total_amount,transaction_uuid,product_code',
            'bloodRequest' => $bloodRequest
        ]);
    }

    public function paymentSuccess(Request $request)
    {
        try {
            // Get the JSON data from the URL parameter
            $jsonData = $request->input('data');
            $paymentData = json_decode(base64_decode($jsonData), true);

            if (!$paymentData) {
                throw new \Exception('Invalid payment data received');
            }

            // Extract needed values
            $transactionId = $paymentData['transaction_uuid'] ?? null;
            $amount = $paymentData['total_amount'] ?? null;
            $status = $paymentData['status'] ?? null;

            $sessionData = $request->session()->get('blood_request');
            if (!$sessionData) {
                throw new \Exception('Session expired. Please submit your request again.');
            }

            // Verify payment status
            if ($status !== 'COMPLETE') {
                throw new \Exception('Payment not completed');
            }

            // Create blood request record
            $bloodRequest = BloodRequest::create([
                'user_id' => $sessionData['user_id'],
                'admin_id' => $sessionData['admin_id'],
                'user_name' => $sessionData['user_name'],
                'email' => $sessionData['email'],
                'phone' => $sessionData['phone'],
                'blood_group' => $sessionData['blood_group'],
                'blood_quantity' => $sessionData['blood_quantity'],
                'request_type' => $sessionData['request_type'],
                'request_form' => $sessionData['request_form'],
                'payment' => $this->formatAmount($amount), // Clean amount
                'payment_status' => 'paid',
                'transaction_id' => $transactionId,
                'status' => 'pending'
            ]);

            // Clear session
            $request->session()->forget(['blood_request', 'transaction_uuid']);

            return view('payment_success', [
                'request_id' => $bloodRequest->id,
                'transaction_id' => $transactionId,
                'amount' => number_format($this->formatAmount($amount), 2)
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Payment processing failed: ' . $e->getMessage());
            return redirect()->route('payment.failure')
                ->with('error', $e->getMessage())
                ->with('transaction_id', $transactionId ?? null);
        }
    }

    private function formatAmount($amount)
    {
        // Convert "1,000.00" to 1000.00
        return str_replace(',', '', $amount);
    }


    public function paymentFailure(Request $request)
    {
        return view('payment_failure');
    }

    private function verifyEsewaPayment($refId, $oid, $amount)
    {
        // For testing environment, skip verification
        if (app()->environment('local', 'testing')) {
            return true;
        }

        try {
            $verificationUrl = "https://rc-epay.esewa.com.np/api/epay/transaction/status/".$oid;

            $client = new \GuzzleHttp\Client();
            $response = $client->request('POST', $verificationUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'transaction_uuid' => $oid,
                    'total_amount' => $amount
                ]
            ]);

            $responseData = json_decode($response->getBody(), true);

            return isset($responseData['status']) &&
                $responseData['status'] === 'COMPLETE' &&
                $responseData['total_amount'] == $amount &&
                $responseData['transaction_uuid'] == $oid;

        } catch (\Exception $e) {
            //\Log::error('eSewa verification failed: '.$e->getMessage());
            return false;
        }
    }
}
