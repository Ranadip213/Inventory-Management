<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Auth;
use Illuminate\Support\Carbon;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Customer;
use DB;


class InvoiceController extends Controller
{
    //

    public function InvoiceAll()
    {
        $allData = Invoice::orderBy('date', 'desc')->orderBy('id', 'desc')->where('status', '1')->get();
        return view('backend.invoice.invoice_all', compact('allData'));
    }

    public function InvoiceAdd()
    {
        $category = Category::all();
        $costomer = Customer::all();
        $invoice_data = Invoice::orderBy('id', 'desc')->first();
        if ($invoice_data == null) {
            $firstReg = '0';
            $invoice_no = $firstReg + 1;
        } else {
            $invoice_data = Invoice::orderBy('id', 'desc')->first()->invoice_no;
            $invoice_no = $invoice_data + 1;
        }
        $date = date('Y-m-d');
        return view('backend.invoice.invoice_add', compact('invoice_no', 'category', 'date', 'costomer'));
    }

    public function InvoiceStore(Request $request)
    {
        // ✅ 1. VALIDATION (MANDATORY)
        $request->validate([
            'invoice_no' => 'required|string',
            'date' => 'required|date',
            'category_id' => 'required|array',
            'product_id' => 'required|array',
            'selling_qty' => 'required|array',
            'unit_price' => 'required|array',
            'selling_price' => 'required|array',
            'estimated_amount' => 'required|numeric',
        ]);

        // ✅ 2. BUSINESS RULES
        if ($request->paid_amount > $request->estimated_amount) {
            return back()->with([
                'message' => 'Paid amount cannot exceed total',
                'alert-type' => 'error'
            ]);
        }

        DB::transaction(function () use ($request) {

            // ✅ 3. CREATE INVOICE
            $invoice = new Invoice();
            $invoice->invoice_no = $request->invoice_no;
            $invoice->date = date('Y-m-d', strtotime($request->date));
            $invoice->description = $request->description;
            $invoice->status = 0;
            $invoice->created_by = Auth::id();
            $invoice->save();

            // ✅ 4. CREATE INVOICE DETAILS
            foreach ($request->product_id as $key => $product_id) {

                InvoiceDetail::create([
                    'date' => date('Y-m-d', strtotime($request->date)),
                    'invoice_id' => $invoice->id,
                    'category_id' => $request->category_id[$key],
                    'product_id' => $product_id,
                    'selling_qty' => $request->selling_qty[$key],
                    'unit_price' => $request->unit_price[$key],
                    'selling_price' => $request->selling_price[$key],
                    'status' => 1,
                ]);

                // ✅ (OPTIONAL BUT IMPORTANT) STOCK UPDATE
                // Product::find($product_id)->decrement('quantity', $request->selling_qty[$key]);
            }

            // ✅ 5. CUSTOMER HANDLING
            if ($request->customer_id == 0) {
                $customer = Customer::create([
                    'name' => $request->name,
                    'mobile_no' => $request->phone,
                    'email' => $request->email,
                ]);
                $customer_id = $customer->id;
            } else {
                $customer_id = $request->customer_id;
            }

            // ✅ 6. PAYMENT
            $paid_amount = 0;
            $due_amount = 0;
            $current_paid = 0;

            if ($request->paid_status == 'full_paid') {
                $paid_amount = $request->estimated_amount;
                $current_paid = $request->estimated_amount;
            } elseif ($request->paid_status == 'full_due') {
                $due_amount = $request->estimated_amount;
            } else {
                $paid_amount = $request->paid_amount;
                $due_amount = $request->estimated_amount - $request->paid_amount;
                $current_paid = $request->paid_amount;
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'customer_id' => $customer_id,
                'paid_status' => $request->paid_status,
                'discount_amount' => $request->discount_amount ?? 0,
                'total_amount' => $request->estimated_amount,
                'paid_amount' => $paid_amount,
                'due_amount' => $due_amount,
            ]);

            PaymentDetail::create([
                'invoice_id' => $invoice->id,
                'date' => date('Y-m-d', strtotime($request->date)),
                'current_paid_amount' => $current_paid,
            ]);
        });

        return redirect()->route('invoice.pending.list')->with([
            'message' => 'Invoice Created Successfully',
            'alert-type' => 'success'
        ]);
    }

    // public function InvoiceStore(Request $request)
    // {
    //     if ($request->category_id == null) {
    //         $notification = array(
    //             'message' => 'Sorry You Do not select any item',
    //             'alert-type' => 'error'
    //         );

    //         return redirect()->back()->with($notification);
    //     } else {
    //         if ($request->paid_amount > $request->estimated_amount) {
    //             $notification = array(
    //                 'message' => 'Sorry Paid Amount is greater than Grand Total',
    //                 'alert-type' => 'error'
    //             );

    //             return redirect()->back()->with($notification);
    //         } else {
    //             $invoice = new Invoice();
    //             $invoice->invoice_no = $request->invoice_no;
    //             $invoice->date = date('Y-m-d', strtotime($request->date));
    //             $invoice->description = $request->description;
    //             $invoice->status = '0';
    //             $invoice->created_by = Auth::user()->id;

    //             DB::transaction(function () use ($request, $invoice) {
    //                 if ($invoice->save()) {
    //                     $count_category = count($request->category_id);
    //                     for ($i = 0; $i < $count_category; $i++) {

    //                         $invoice_details = new InvoiceDetail();
    //                         $invoice_details->date = date('Y-m-d', strtotime($request->date));
    //                         $invoice_details->invoice_id = $invoice->id;
    //                         $invoice_details->category_id = $request->category_id[$i];
    //                         $invoice_details->product_id = $request->product_id[$i];
    //                         $invoice_details->selling_qty = $request->selling_qty[$i];
    //                         $invoice_details->unit_price = $request->unit_price[$i];
    //                         $invoice_details->selling_price = $request->selling_price[$i];
    //                         $invoice_details->status = '1';
    //                         $invoice_details->save();
    //                     }

    //                     if ($request->customer_id == '0') {
    //                         $customer = new Customer();
    //                         $customer->name = $request->name;
    //                         $customer->mobile_no = $request->phone;
    //                         $customer->email = $request->email;
    //                         $customer->save();
    //                         $customer_id = $customer->id;
    //                     } else {
    //                         $customer_id = $request->customer_id;
    //                     }


    //                     $payment = new Payment();
    //                     $payment_details = new PaymentDetail();

    //                     $payment->invoice_id = $invoice->id;
    //                     $payment->customer_id = $customer_id;
    //                     $payment->paid_status = $request->paid_status;
    //                     $payment->discount_amount = $request->discount_amount;
    //                     $payment->total_amount = $request->estimated_amount;

    //                     if ($request->paid_status == 'full_paid') {
    //                         $payment->paid_amount = $request->estimated_amount;
    //                         $payment->due_amount = '0';
    //                         $payment_details->current_paid_amount = $request->estimated_amount;
    //                     } elseif ($request->paid_status == 'full_due') {
    //                         $payment->paid_amount = '0';
    //                         $payment->due_amount = $request->estimated_amount;
    //                         $payment_details->current_paid_amount = '0';
    //                     } elseif ($request->paid_status == 'partial_paid') {
    //                         $payment->paid_amount = $request->paid_amount;
    //                         $payment->due_amount = $request->estimated_amount - $request->paid_amount;
    //                         $payment_details->current_paid_amount = $request->paid_amount;
    //                     }
    //                     $payment->save();

    //                     $payment_details->invoice_id = $invoice->id;
    //                     $payment_details->date = date('Y-m-d', strtotime($request->date));
    //                     $payment_details->save();
    //                 }
    //             });



    //         }
    //     }

    //     $notification = array(
    //         'message' => 'Invoice Data Inserted Successfully',
    //         'alert-type' => 'success'
    //     );
    //     return redirect()->route('invoice.all')->with($notification);

    // }



    public function PendingList()
    {
        $allData = Invoice::orderBy('date', 'desc')->orderBy('id', 'desc')->where('status', '0')->get();
        return view('backend.invoice.invoice_pending_list', compact('allData'));
    }

    public function InvoiceDelete($id)
    {

        $invoice = Invoice::findOrFail($id);
        $invoice->delete();
        InvoiceDetail::where('invoice_id', $invoice->id)->delete();
        Payment::where('invoice_id', $invoice->id)->delete();
        PaymentDetail::where('invoice_id', $invoice->id)->delete();

        $notification = array(
            'message' => 'Invoice Deleted Successfully',
            'alert-type' => 'success'
        );
        return redirect()->back()->with($notification);

    }

    public function InvoiceApprove($id)
    {

        $invoice = Invoice::with('invoice_details')->findOrFail($id);
        return view('backend.invoice.invoice_approve', compact('invoice'));

    }

    public function ApprovalStore(Request $request, $id)
    {

        foreach ($request->selling_qty as $key => $val) {
            $invoice_details = InvoiceDetail::where('id', $key)->first();
            $product = Product::where('id', $invoice_details->product_id)->first();
            if ($product->quantity < $request->selling_qty[$key]) {

                $notification = array(
                    'message' => 'Sorry you approve Maximum Value',
                    'alert-type' => 'error'
                );
                return redirect()->back()->with($notification);

            }
        } // End foreach 


        $invoice = Invoice::findOrFail($id);
        $invoice->updated_by = Auth::user()->id;
        $invoice->status = 1;

        DB::transaction(function () use ($request, $invoice) {
            foreach ($request->selling_qty as $key => $val) {
                $invoice_details = InvoiceDetail::where('id', $key)->first();

                $invoice_details->status = 1;
                $invoice_details->save();

                $product = Product::where('id', $invoice_details->product_id)->first();
                $product->quantity = ((float) $product->quantity) - ((float) $request->selling_qty[$key]);
                $product->save();

            }

            $invoice->save();

        });


        $notification = array(
            'message' => 'Invoice approved successfull',
            'alert-type' => 'success'
        );
        return redirect()->route('invoice.pending.list')->with($notification);



    } // End Method


    public function PrintInvoiceList()
    {

        $allData = Invoice::orderBy('date', 'desc')->orderBy('id', 'desc')->where('status', '1')->get();
        return view('backend.invoice.print_invoice_list', compact('allData'));
    } // End Method


    public function PrintInvoice($id)
    {
        $invoice = Invoice::with('invoice_details')->findOrFail($id);
        return view('backend.pdf.invoice_pdf', compact('invoice'));

    }

    public function DailyInvoiceReport()
    {
        return view('backend.invoice.daily_invoice_report');
    }

    public function DailyInvoicePdf(Request $request)
    {

        $sdate = date('Y-m-d', strtotime($request->start_date));
        $edate = date('Y-m-d', strtotime($request->end_date));
        $allData = Invoice::whereBetween('date', [$sdate, $edate])->where('status', '1')->get();


        $start_date = date('Y-m-d', strtotime($request->start_date));
        $end_date = date('Y-m-d', strtotime($request->end_date));
        return view('backend.pdf.daily_invoice_report_pdf', compact('allData', 'start_date', 'end_date'));
    } // End Method
}
