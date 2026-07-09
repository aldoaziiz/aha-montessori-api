<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body {
	font-family: sans-serif;
	font-size: 12px;
}

table {
	width: 100%;
	border-collapse: collapse;
}

th,
td {
	border: 1px solid #ddd;
	padding: 8px;
}

th {
	background: #f5f5f5;
}

.right {
	text-align: right;
}

.header {
	margin-bottom: 20px;
}

.total {
	margin-top: 20px;
	font-size: 16px;
	font-weight: bold;
}

.company-table {
	width: 100%;
	border: none;
	margin-bottom: 25px;
}

.info-table {
	width: 100%;
	border: none;
	margin-bottom: 25px;
}

.info-table td {
	border: none;
	vertical-align: top;
	padding: 0;
}

.info-title {
	font-size: 13px;
	font-weight: bold;
	margin-bottom: 8px;
}

.info-table p {
	margin: 4px 0;
}

.company-table td {
	border: none;
	vertical-align: top;
	padding: 0;
}

.logo {
	width: 90px;
}

.company-name {
	font-size: 20px;
	font-weight: bold;
}

.company-subtitle {
	color: #666;
	font-size: 11px;
	margin-top: 3px;
}

.invoice-title {
	text-align: right;
}

.invoice-title h1 {
	margin: 0;
	font-size: 28px;
}

.invoice-info {
	margin-top: 8px;
	font-size: 11px;
}

.invoice-info p {
	margin: 3px 0;
}

.items-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 15px;
}

.items-table th {
	background: #2F5597;
	color: white;
	font-weight: bold;
	font-size: 12px;
	padding: 8px;
}

.items-table td {
	padding: 8px;
	border: 1px solid #dcdcdc;
	font-size: 11px;
}

.center {
	text-align: center;
}

.right {
	text-align: right;
}

.summary-table {
	width: 45%;
	margin-left: auto;
	margin-top: 20px;
	border-collapse: collapse;
}

.summary-table td {
	border: none;
	padding: 6px 0;
	font-size: 12px;
}

.summary-label {
	text-align: left;
}

.summary-value {
	text-align: right;
}

.summary-total td {
	border-top: 2px solid #2F5597;
	font-weight: bold;
	font-size: 14px;
	padding-top: 10px;
}

.footer {
	margin-top: 50px;
	font-size: 11px;
	color: #555;
}

.footer-note {
	margin-top: 20px;
	border-top: 1px solid #cccccc;
	padding-top: 12px;
	color: #777;
	font-style: italic;
}

.footer-table {
	width: 100%;
	border: none;
}

.footer-table td {
	border: none;
	vertical-align: top;
	padding: 0;
}

.footer-title {
	font-weight: bold;
	color: #333;
	margin-bottom: 6px;
}

.footer-note {
	color: #777;
	font-style: italic;
}

</style>

</head>

<body>
    <table class="company-table">

    <tr>

        <td width="70%">

    <table style="width:100%; border:none;">
        <tr>

            <td style="width:90px; border:none; vertical-align:top;">

                <img
                    src="{{ public_path('assets/images/ahamon-logo.jpeg') }}"
                    style="width:70px;"
                >

            </td>

            <td style="border:none; vertical-align:top;">

                <div class="company-name">
                    AHA! Child Development Center
                </div>

                <div class="company-subtitle">
                    P7 BTN PKT, Jalan Gelatik, Kelurahan Belimbing, Kecamatan Bontang Barat
                </div>

                <div class="company-subtitle">
                    Kota Bontang, Kalimantan Timur, Kode Pos 75325
                </div>

            </td>

        </tr>
    </table>

</td>

        <td width="30%" class="invoice-title">

            <h1>INVOICE</h1>

        </td>

    </tr>
</table>

<table class="info-table">

    <tr>

        <td width="55%">

            <div class="info-title">
                Bill For
            </div>

            <p>
                <strong>Registration No</strong><br>
                {{ $billing->registration->registration_number }}
            </p>

            <p>
                <strong>Child Name</strong><br>
                {{ $billing->registration->child->name }}
            </p>

            <p>
                <strong>Payment</strong><br>
                {{ $billing->registration->payer->name ?? '-' }}
            </p>

        </td>

        <td width="45%">

            <div class="info-title">
                Payment Information
            </div>

            <p>
                <strong>Invoice Number</strong><br>
                {{ $billing->invoice_number }}
            </p>

            <p>
                <strong>Invoice Date</strong><br>
                {{ $billing->created_at->format('d M Y') }}
            </p>

            <p>
                <strong>Status</strong><br>
                {{ $billing->paymentStatus->name }}
            </p>

        </td>

    </tr>

</table>

    <table class="items-table">
        <thead>
<tr>

    <th width="8%">No</th>

    <th>Description</th>

    <th width="12%">Qty</th>

    <th width="20%">Unit Price</th>

    <th width="22%">Subtotal</th>

</tr>
</thead>

        <tbody>

@foreach($billing->items as $index => $item)

<tr>

    <td class="center">
        {{ $index + 1 }}
    </td>

    <td>
        {{ $item->description }}
    </td>

    <td class="center">
        {{ $item->quantity }}
    </td>

    <td class="right">
        Rp {{ number_format($item->price,0,',','.') }}
    </td>

    <td class="right">
        Rp {{ number_format($item->subtotal,0,',','.') }}
    </td>

</tr>

@endforeach

</tbody>
    </table>

    <table class="summary-table">

    <tr>
        <td class="summary-label">
            Subtotal
        </td>

        <td class="summary-value">
            Rp {{ number_format($billing->total_amount, 0, ',', '.') }}
        </td>
    </tr>

    <tr>
        <td class="summary-label">
            Discount
        </td>

        <td class="summary-value">
            -
        </td>
    </tr>

    <tr class="summary-total">

        <td>
            TOTAL
        </td>

        <td class="summary-value">
            Rp {{ number_format($billing->total_amount, 0, ',', '.') }}
        </td>

    </tr>

</table>

<div class="footer">

    <div style="text-align: right; margin-bottom: 40px;">

        Bontang,
        {{ $generatedAt->translatedFormat('j F Y') }}

        <br><br><br><br>

        <strong>
            {{ $downloadedBy->name }}
        </strong>

    </div>

    <div class="footer-note">

        <strong>
            Thank you for your trust.
        </strong>

        <br>

        This invoice is generated electronically by
        AHA! Child Development Center.

    </div>

</div>
</body>
</html>