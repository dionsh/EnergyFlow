# Password reset email setup

EnergyFlow sends password reset links through EmailJS from the backend. Reset tokens are never sent from the browser or printed in the backend terminal. The email template is in [`emailjs-password-reset-template.html`](emailjs-password-reset-template.html).

## EmailJS setup

1. In EmailJS, connect the email service you want to send from.
2. Open the existing password reset template (`template_kr0bemn`) or create one. Paste the HTML from `emailjs-password-reset-template.html` into its content and save it.
3. In the template settings, set **To Email** to `{{email}}` and **Subject** to `{{subject}}`.
4. In **Account → Security**, enable **Allow EmailJS API for non-browser applications**. Keep the private key server-side.
5. Add these values to `backend/.env` for local development:

   ```env
   FRONTEND_URL=http://localhost:5173
   EMAILJS_SERVICE_ID=your_service_id
   EMAILJS_TEMPLATE_ID=template_kr0bemn
   EMAILJS_PUBLIC_KEY=your_public_key
   EMAILJS_PRIVATE_KEY=your_private_key
   ```

   The template ID is the value shown in the template's Settings, not the short slug in the dashboard URL. Do not put these keys in frontend code, commit them, or paste them into chat.

6. Restart the PHP backend and request a password reset. Check the backend log and EmailJS email history if delivery fails.

## Production

Set `FRONTEND_URL` and the four `EMAILJS_*` values as Render environment variables. `FRONTEND_URL` must be the deployed Vercel app URL. Do not configure the EmailJS private key in Vercel.

If the mail settings are missing, the API returns `email_not_configured`; it does not create a reset token or print a link to the terminal.
