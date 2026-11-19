# Billing: Stripe test-mode checklist

This is the owner's manual check that a trial converts to paid, which is the Phase 8 acceptance criterion. Do it in Stripe **test mode** only. The automated tests never contact Stripe (`tests/Support/FakeStripe.php`), so this is the only place real Stripe calls happen.

Background is in decisions D80 (billing), D81 (read-only after grace) and D82 (soft limits) in `docs/DECISIONS.md`.

## 1. Keys

1. Sign in at <https://dashboard.stripe.com> and switch on **Test mode** (top right).
2. Go to **Developers › API keys** and copy the two test keys into `.env`. Never commit them.

   ```dotenv
   STRIPE_KEY=pk_test_...
   STRIPE_SECRET=sk_test_...
   CASHIER_CURRENCY=inr
   CASHIER_CURRENCY_LOCALE=en_IN
   ```

3. Run `php artisan config:clear`.

Without these keys, the Billing page says "Payments aren't set up on this server yet". Everything else in the app keeps working.

## 2. Products and prices

1. In **Product catalog**, create three products: **Coffer Starter**, **Coffer Growth** and **Coffer Business**.
2. Give each product **recurring** prices:
   - monthly and yearly in INR
   - optionally monthly and yearly in USD
3. Copy each price id (`price_...`) into `.env`:

   ```dotenv
   STRIPE_PRICE_STARTER_MONTHLY_INR=price_...
   STRIPE_PRICE_STARTER_YEARLY_INR=price_...
   STRIPE_PRICE_GROWTH_MONTHLY_INR=price_...
   STRIPE_PRICE_GROWTH_YEARLY_INR=price_...
   STRIPE_PRICE_BUSINESS_MONTHLY_INR=price_...
   STRIPE_PRICE_BUSINESS_YEARLY_INR=price_...
   # and the _USD ones if you made them
   ```

4. Run `php artisan config:clear`.

How the pieces fit:

- **Enterprise has no Stripe price.** It's "Talk to us".
- **An empty price id leaves that option unbuyable.** The Upgrade modal shows it as not available yet.
- **Shown prices and charged prices come from different places.** The `plans` table (seeded with placeholders by `PlanSeeder`, later edited in the super-admin panel) sets what Coffer *shows*. Stripe's price sets what is *charged*. Keep the two equal.

## 3. Billing Portal

Go to **Settings › Billing › Customer portal** and save a configuration that allows:

- updating the payment method
- viewing invoice history
- cancelling at the end of the period

Also turn on **switching plans**, with the three Coffer products and their prices. Upgrades happen in Coffer's Upgrade modal, but once a workspace is subscribed the modal disables cheaper plans, so downgrades go through the portal. The webhook brings the new plan back into Coffer.

## 4. Webhooks (local)

1. Install the Stripe CLI (<https://docs.stripe.com/stripe-cli>), then run `stripe login`.
2. Leave this running in its own terminal:

   ```bash
   stripe listen --forward-to http://localhost:8000/stripe/webhook \
     --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,customer.updated,customer.deleted,payment_method.automatically_updated,invoice.payment_action_required,invoice.payment_succeeded,invoice.paid,invoice.payment_failed,customer.subscription.trial_will_end
   ```

3. It prints a signing secret. Put it in `.env` as `STRIPE_WEBHOOK_SECRET=whsec_...`, then run `php artisan config:clear`.
4. Keep `composer run dev` running. It includes the queue worker that sends notification emails. Locally, mail goes to the log (`MAIL_MAILER=log`) unless you set up Mailpit or another mailer.

The endpoint is Cashier's `/stripe/webhook`. It checks the signature and syncs the subscription. **Without `STRIPE_WEBHOOK_SECRET` every webhook is refused (403, D117)**, so run `stripe listen` and copy its secret before testing webhooks. You don't need webhooks to see a Checkout land: on the way back Coffer reads the Checkout Session from Stripe and syncs the subscription and card straight away (D117), and **Refresh from Stripe** on Billing does the same at any time. `App\Listeners\SyncWorkspaceBilling` then copies the plan, cycle, currency and renewal date onto the workspace, and tracks failed payments.

## 5. Convert a trial to paid (the acceptance test)

1. Run `php artisan migrate:fresh --seed`, or sign up a new account (every new workspace gets the 14-day no-card trial on Growth's limits).
2. Sign in as `aarav@vertexlabs.in` with the password `password`. Open **Billing** (`/billing`). It should show **Free trial** with the days left.
3. Choose **Upgrade**, then **Growth**, **Monthly**, **Continue with Growth**. Stripe Checkout opens.
4. Pay with the test card `4242 4242 4242 4242`, any future expiry, any CVC and any address. For an Indian card, use `4000 0035 6000 0008`.
5. You land back on `/billing?checkout=done&session_id=cs_...`. Coffer reads that session from Stripe at once (no webhook needed) and the page shows:
   - the Growth plan, monthly
   - the card's last four digits
   - the renewal date
6. **The trial carries over.** Checkout keeps the days left on the no-card trial, so the subscription starts as `trialing` and the first charge happens when the trial ends. To see the charge right away, either:
   - in the Stripe Dashboard, open the subscription and choose **Actions › End trial now**, or
   - before step 3, end the Coffer trial: `php artisan tinker`, then `App\Models\Workspace::first()->update(['trial_ends_at' => now()->subMinute()]);`. Checkout then charges at once.
7. A paid invoice appears under **Invoices** on `/billing`. Its download is a PDF with the billing name and GSTIN from **Edit billing details**.

Passing means the workspace shows the paid plan, an invoice is listed, the trial banner is gone, and `stripe listen` shows `200` for every event.

## 6. More checks

- **Change plan:**
  1. Open **Upgrade**, choose **Business**, then confirm. The plan swaps in place, with a prorated invoice charged now.
  2. Check that no second subscription appears in Stripe.
- **Cancel and resume:**
  1. In the Billing Portal (**Cancel plan** on the plan card), cancel the plan. Coffer shows that it ends on the period's last day.
  2. Resume it with **Resume plan** (the portal), or choose the same plan in the Upgrade modal. Either way it is resumed, not bought twice.
- **Failed payment:**
  1. In the portal, switch the card to `4000 0000 0000 0341`, which attaches but declines.
  2. End the trial, or wait for renewal. Stripe sends `invoice.payment_failed`.
  3. Check that the owner and Admins get a "payment failed" notification and email, and the shell shows the overdue banner.
  4. After `BILLING_GRACE_DAYS` (7), the workspace becomes read-only: pages load, changes open the Upgrade modal. To test this sooner, set `BILLING_GRACE_DAYS=0`.
  5. Pay the invoice with a good card. It clears at once.
- **Trial ending:** Stripe's `customer.subscription.trial_will_end` notifies the owner and Admins. For the no-card trial, run `php artisan coffer:reminders` with a workspace whose `trial_ends_at` is 3 days or 1 day away.
- **Soft limits:** on Starter, a second company, a fourth person or an eleventh vault item opens the Upgrade modal. These are placeholder limits, set in `PlanSeeder` / the plans editor.

## 7. Going live

1. Create the same products and prices in **live** mode.
2. Put the live keys and live price ids in the production `.env`.
3. Create the webhook endpoint with `php artisan cashier:webhook`. It registers `APP_URL/stripe/webhook` with every event listed in `config/cashier.php`, and `APP_URL` must be the public https URL. Copy that endpoint's signing secret into `STRIPE_WEBHOOK_SECRET`.
4. Configure the live Customer portal as in step 3.
5. Decide on GST: Stripe Tax, or GST added on invoices [VERIFY]. Set the business name and address in Stripe so they appear on invoices.

## Troubleshooting

| You see | Cause |
|---|---|
| "Online payments aren't available yet" | `STRIPE_KEY` / `STRIPE_SECRET` are missing, or the config is cached (`php artisan config:clear`). |
| "Growth isn't set up for monthly payments in INR yet" | That `STRIPE_PRICE_*` id is empty. |
| "Stripe couldn't be reached just now" | A Stripe API error. The details are in `storage/logs/laravel.log`. Nothing was charged. |
| Webhooks return 403 | `STRIPE_WEBHOOK_SECRET` doesn't match the one `stripe listen` printed. |
| Plan doesn't change after Checkout | Use **Refresh from Stripe** on Billing. If that finds nothing, the Checkout didn't finish. Later changes (portal, renewals) still need webhooks: is `stripe listen` running, with the events above? |
| "Online payments aren't available yet" (customers) | The server has no Stripe keys. Locally the page also says which keys to add. |
| "... can't be bought right now: its Stripe price doesn't match" | A `STRIPE_PRICE_*` id points at a Stripe price whose amount or currency differs from the plan in /admin (Plans). The details are logged (`Billing: Stripe price does not match the plan`). |
| "... is too small for this workspace" | The workspace already uses more than that plan allows (D118). |

## Razorpay

Not built. Spec §20 calls it "a later option". `App\Services\Billing\PaymentGateway` is the seam: a `RazorpayGateway` using the `razorpay/razorpay` SDK would implement it, chosen by `BILLING_GATEWAY=razorpay`, and the `RAZORPAY_*` keys are already reserved in `.env.example` [VERIFY when it's needed].
