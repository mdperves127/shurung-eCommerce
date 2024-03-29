<?php

namespace App\Http\Controllers;

use App\Brand;
use App\Category;
use App\Library\SslCommerz\SslCommerzNotification;
use App\Http\Requests\OrderRequest;
use Illuminate\Http\Request;
use App\Product;
use App\Orderdetails;
use Carbon\Carbon;
use DB;
use Auth;

class SslCommerzPaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }


    public function index(OrderRequest $request)
    {

        # Here you have to receive all the order data to initate the payment.
        # Let's say, your oder transaction informations are saving in a table called "orders"
        # In "orders" table, order unique identity is "transaction_id". "status" field contain status of the transaction, "amount" is the order amount to be paid and "currency" is for storing Site Currency which will be checked with paid currency.

        $post_data = array();
        $post_data['total_amount'] = session('total'); # You cant not pay less than 10
        $post_data['currency'] = "BDT";
        $post_data['tran_id'] = uniqid(); // tran_id must be unique

        # CUSTOMER INFORMATION
        $post_data['cus_name'] = $request->cus_name;
        $post_data['cus_email'] = $request->cus_email;
        $post_data['cus_add1'] = $request->cus_address;
        $post_data['cus_add2'] = "";
        $post_data['cus_city'] = $request->cus_city;
        $post_data['cus_state'] = '';
        $post_data['cus_postcode'] = $request->cus_postcode;
        $post_data['cus_country'] = $request->cus_country;
        $post_data['cus_phone'] = $request->cus_phone;
        $post_data['cus_fax'] = "";

        # SHIPMENT INFORMATION
        $post_data['ship_name'] = $request->cus_name;
        $post_data['ship_email'] = $request->cus_email;
        $post_data['ship_add1'] = $request->cus_address;
        $post_data['ship_add2'] = "Dhaka";
        $post_data['ship_city'] = $request->cus_city;
        $post_data['ship_country'] = $request->cus_country;
        $post_data['ship_state'] = "Dhaka";
        $post_data['ship_postcode'] = $request->cus_postcode;
        $post_data['ship_phone'] = $request->cus_phone;

        $post_data['shipping_method'] = "NO";
        $post_data['product_name'] = "Computer";
        $post_data['product_category'] = "Goods";
        $post_data['product_profile'] = "physical-goods";

        # OPTIONAL PARAMETERS
        $post_data['value_a'] = "ref001";
        $post_data['value_b'] = "ref002";
        $post_data['value_c'] = "ref003";
        $post_data['value_d'] = "ref004";


        if ($request->shippingStatus == 'on') {
            $request->validate([
                'ship_name' => 'required|max:255',
                'ship_email' => 'required|max:255',
                'ship_address' => 'required|max:1024',
                'ship_city' => 'required|max:255',
                'ship_country' => 'required|max:255',
                'ship_postcode' => 'required|max:255',
                'ship_phone' => 'required|max:255',
            ]);
            $post_data['ship_name'] = $request->ship_name;
            $post_data['ship_email'] = $request->cus_email;
            $post_data['ship_add1'] = $request->ship_address;
            $post_data['ship_city'] = $request->ship_city;
            $post_data['ship_country'] = $request->ship_country;
            $post_data['ship_postcode'] = $request->ship_postcode;
            $post_data['ship_phone'] = $request->ship_phone;
        }


        foreach (cart_items() as $cart_item) {
            $product =  Product::find($cart_item->product_id);
            $brandName = Brand::find($product->brand_id)->name;
            $brandCategory = Category::find($product->category_id)->name;

            Orderdetails::insert([
                'order_id' => $post_data['tran_id'],
                'user_id' => Auth::id(),
                'product_id' => $cart_item->product_id,
                'brand_name' => $brandName,
                'category_Name' => $brandCategory,
                'product_name' => $product->name,
                'product_quantity' => $cart_item->product_quantity,
                'product_price' => $product->discount_price,
                'created_at' => Carbon::now()
            ]);
            //    product table ar quantity decrement
            Product::find($cart_item->product_id)->decrement('stock', $cart_item->product_quantity);
            //    cart item deleted
            $cart_item->forceDelete();
        };
        
        #Before  going to initiate the payment order status need to insert or update as Pending.
        $update_product = DB::table('orders')
            ->where('transaction_id', $post_data['tran_id'])
            ->updateOrInsert([
                'user_id' => Auth::id(),
                'name' => $post_data['cus_name'],
                'email' => $post_data['cus_email'],
                'phone' => $post_data['cus_phone'],
                'cus_city' => $post_data['cus_city'],
                'cus_country' => $post_data['cus_country'],
                'cus_postcode' => $post_data['cus_postcode'],
                'address' => $post_data['cus_add1'],
                'addressStatus' => $request->shippingStatus,
                'note' => $request->note,
                'ship_name' => $post_data['ship_name'],
                'ship_email' => $post_data['ship_email'],
                'ship_phone' => $post_data['ship_phone'],
                'ship_address' => $post_data['ship_add1'],
                'ship_city' => $post_data['ship_city'],
                'ship_country' => $post_data['ship_country'],
                'ship_postcode' => $post_data['ship_postcode'],
                'status' => 'Pending',
                'payment_type' => $request->payment_type,
                'amount' => $post_data['total_amount'],
                'transaction_id' => $post_data['tran_id'],
                'currency' => $post_data['currency'],
                'created_at' => Carbon::now()
            ]);
        
            if ($request->payment_type == 'cash_on_delivery') {
                return  redirect('cart')->withSuccess('Order Successful. You will get your product soon.');
            }
        $sslc = new SslCommerzNotification();
        # initiate(Transaction Data , false: Redirect to SSLCOMMERZ gateway/ true: Show all the Payement gateway here )
        $payment_options = $sslc->makePayment($post_data, 'hosted');

        if (!is_array($payment_options)) {
            print_r($payment_options);
            $payment_options = array();
        }
    }


    public function success(Request $request)
    {


        $tran_id = $request->input('tran_id');
        $amount = $request->input('amount');
        $currency = $request->input('currency');

        $sslc = new SslCommerzNotification();

        #Check order status in order tabel against the transaction id or order id.
        $order_detials = DB::table('orders')
            ->where('transaction_id', $tran_id)
            ->select('transaction_id', 'status', 'currency', 'amount')->first();

        if ($order_detials->status == 'Pending') {
            $validation = $sslc->orderValidate($request->all(), $tran_id, $amount, $currency);

            if ($validation == TRUE) {
                /*
                That means IPN did not work or IPN URL was not set in your merchant panel. Here you need to update order status
                in order table as Processing or Complete.
                Here you can also sent sms or email for successfull transaction to customer
                */
                $update_product = DB::table('orders')
                    ->where('transaction_id', $tran_id)
                    ->update([
                        'status' => 'Processing',
                        'created_at' => Carbon::now(),
                    ]);

                    session(['cart_subtitle' => ' ']);
                return redirect()->route('cart')->withSuccess('Payment Successful. You will get your product soon.');
            } else {
                /*
                That means IPN did not work or IPN URL was not set in your merchant panel and Transation validation failed.
                Here you need to update order status as Failed in order table.
                */
                $update_product = DB::table('orders')
                    ->where('transaction_id', $tran_id)
                    ->update(['status' => 'Failed']);
                    return redirect()->route('cart')->withError("Validate Fail");
            }
        } else if ($order_detials->status == 'Processing' || $order_detials->status == 'Complete') {
            /*
             That means through IPN Order status already updated. Now you can just show the customer that transaction is completed. No need to udate database.
             */
            
            session(['cart_subtitle' => ' ']);
            return redirect()->route('cart');
        } else {
            #That means something wrong happened. You can redirect customer to your product page.
            return redirect()->route('cart')->withError("Invalid Transaction");
        }
    }

    public function fail(Request $request)
    {
        $tran_id = $request->input('tran_id');

        $order_detials = DB::table('orders')
            ->where('transaction_id', $tran_id)
            ->select('transaction_id', 'status', 'currency', 'amount')->first();

        if ($order_detials->status == 'Pending') {
            $update_product = DB::table('orders')
                ->where('transaction_id', $tran_id)
                ->update(['status' => 'Failed']);
                
            return redirect()->route('cart')->withError("Transaction is failed");
        } else if ($order_detials->status == 'Processing' || $order_detials->status == 'Complete') {
            
            return redirect()->route('cart')->withError("Transaction is already successful");
        } else {
            
            return redirect()->route('cart')->withError("Transaction is invalid");
        }
    }

    public function cancel(Request $request)
    {
        $tran_id = $request->input('tran_id');

        $order_detials = DB::table('orders')
            ->where('transaction_id', $tran_id)
            ->select('transaction_id', 'status', 'currency', 'amount')->first();

        if ($order_detials->status == 'Pending') {
            $update_product = DB::table('orders')
                ->where('transaction_id', $tran_id)
                ->update(['status' => 'Canceled']);
                
            return redirect()->route('cart')->withError("Transaction is Cancel");
        } else if ($order_detials->status == 'Processing' || $order_detials->status == 'Complete') {
            
            return redirect()->route('cart')->withError("Transaction is already successful");
        } else {
            
            return redirect()->route('cart')->withError("Transaction is invalid");
        }
    }

    public function ipn(Request $request)
    {
        #Received all the payement information from the gateway
        if ($request->input('tran_id')) #Check transation id is posted or not.
        {

            $tran_id = $request->input('tran_id');

            #Check order status in order tabel against the transaction id or order id.
            $order_details = DB::table('orders')
                ->where('transaction_id', $tran_id)
                ->select('transaction_id', 'status', 'currency', 'amount')->first();

            if ($order_details->status == 'Pending') {
                $sslc = new SslCommerzNotification();
                $validation = $sslc->orderValidate($request->all(), $tran_id, $order_details->amount, $order_details->currency);
                if ($validation == TRUE) {
                    /*
                    That means IPN worked. Here you need to update order status
                    in order table as Processing or Complete.
                    Here you can also sent sms or email for successful transaction to customer
                    */
                    $update_product = DB::table('orders')
                        ->where('transaction_id', $tran_id)
                        ->update(['status' => 'Processing']);

                        
                    session(['cart_subtitle' => ' ']);
                    return redirect()->route('cart');
                } else {
                    /*
                    That means IPN worked, but Transation validation failed.
                    Here you need to update order status as Failed in order table.
                    */
                    $update_product = DB::table('orders')
                        ->where('transaction_id', $tran_id)
                        ->update(['status' => 'Failed']);

                        
            return redirect()->route('cart')->withError("Validation fail");
                }
            } else if ($order_details->status == 'Processing' || $order_details->status == 'Complete') {

                #That means Order status already updated. No need to udate database.

                return redirect()->route('cart')->withError("Transaction is already completed");
            } else {
                #That means something wrong happened. You can redirect customer to your product page.
                
            return redirect()->route('cart')->withError("Invalid Transaction");
            }
        } else {
            
            return redirect()->route('cart')->withError("Invalid Data");
        }
    }
}
