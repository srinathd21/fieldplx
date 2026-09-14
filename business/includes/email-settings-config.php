<?php
/**
 * FieldPlx Email Settings shared configuration.
 * PHP 7.2+
 */

if (!function_exists('es_catalog')) {
    function es_catalog()
    {
        return array(
            'request_submitted' => array(
                'group' => 'Requests',
                'title' => 'Submitted request',
                'description' => 'Automatically notifies a customer that a request has been received',
                'event_key' => 'request.submitted',
                'type' => 'template',
                'subject' => 'Thanks for your request!',
                'body' => "Hello {{CLIENT_NAME}},\n\nYour request has been received! Thank you for considering us—we'll be in touch soon.\n\nPlease find a copy of your request below.\n\nIf you have any questions regarding this request, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nSincerely,\n\n{{COMPANY_NAME}}",
                'context' => 'request'
            ),
            'request_booking_confirmation' => array(
                'group' => 'Requests',
                'title' => 'Booking confirmation',
                'description' => 'Notifies a customer that an assessment has been scheduled when sent',
                'event_key' => 'assessment.booking_confirmation',
                'type' => 'template',
                'subject' => 'Booking confirmation from {{COMPANY_NAME}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nThank you for booking with us.\n\n{{VISIT_DETAILS}}\n\nSincerely,\n\n{{COMPANY_NAME}}",
                'context' => 'assessment'
            ),
            'request_declining_work' => array(
                'group' => 'Requests',
                'title' => 'Declining work',
                'description' => 'Notifies a customer that you declined their request and can’t take the work',
                'event_key' => 'request.declined',
                'type' => 'template',
                'subject' => 'Thank you for reaching out',
                'body' => "Hello {{CLIENT_NAME}},\n\nThank you so much for reaching out and considering us for this opportunity. Unfortunately, we're not available to take this on at the moment.\n\nIf you have any questions, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nSincerely,\n\n{{COMPANY_NAME}}",
                'context' => 'request'
            ),
            'assessment_reminder' => array(
                'group' => 'Requests',
                'title' => 'Assessment reminder',
                'description' => 'Automatically notifies a customer about an upcoming assessment for a request',
                'event_key' => 'assessment.reminder',
                'type' => 'reminder',
                'toggle' => 1,
                'subject' => 'Scheduled on-site assessment reminder by {{COMPANY_NAME}} - {{APPOINTMENT_DATE}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nJust a friendly reminder that we have an upcoming on-site assessment.\n\n{{VISIT_DETAILS}}\n\nIf you have any questions or concerns, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nCLICK LINK BELOW TO VIEW YOUR APPOINTMENT:\n\n{{VISIT_CONFIRMATION_LINK}}\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'assessment',
                'schedules' => array(
                    array('amount' => 1, 'offset_type' => 'hour_before', 'time_of_day' => null),
                    array('amount' => 1, 'offset_type' => 'day_before', 'time_of_day' => '15:30:00')
                )
            ),
            'request_checklist' => array(
                'group' => 'Requests',
                'title' => 'Checklist',
                'description' => 'A copy of a checklist used during an assessment or visit when sent',
                'event_key' => 'request.checklist_sent',
                'type' => 'template',
                'subject' => '{{JOB_FORM_NAME}} from {{COMPANY_NAME}}',
                'body' => "Hello {{CLIENT_NAME}},\n\nThe {{JOB_FORM_NAME}} document related to your appointment is now available. You can save or print a copy to keep for your records.\n\nIf you have any questions or concerns, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nSincerely,\n\n{{COMPANY_NAME}}",
                'context' => 'checklist'
            ),
            'quote_new' => array(
                'group' => 'Quotes',
                'title' => 'New quote',
                'description' => 'Notifies a customer about a new quote when sent',
                'event_key' => 'quote.sent',
                'type' => 'template',
                'subject' => 'Quote from {{COMPANY_NAME}} - {{CURRENT_DATE}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nThank you for asking us to quote on your project.\n\nThe quote total is {{QUOTE_TOTAL}} as of {{QUOTE_SENT_DATE}}.\n{{QUOTE_CONSUMER_FINANCING}}\n\nIf you have any questions or concerns regarding this quote, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nSincerely,\n\n{{COMPANY_NAME}}",
                'context' => 'quote'
            ),
            'quote_approval' => array(
                'group' => 'Quotes',
                'title' => 'Quote approval',
                'description' => 'Automatically notifies a customer that a quote approval has been received',
                'event_key' => 'quote.approved',
                'type' => 'template',
                'subject' => 'Quote {{QUOTE_NUMBER}} approved',
                'body' => "Hi {{CLIENT_NAME}},\n\nThank you. We received your approval for quote {{QUOTE_NUMBER}}.\n\nOur team will be in touch with the next steps.\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'quote'
            ),
            'job_booking_confirmation' => array(
                'group' => 'Jobs',
                'title' => 'Booking confirmation',
                'description' => 'Notifies a customer that a job has been scheduled when sent and when a customer has submitted an online booking',
                'event_key' => 'job.booking_confirmation',
                'type' => 'template',
                'subject' => 'Booking confirmation from {{COMPANY_NAME}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nYour job has been scheduled.\n\n{{WORK_DETAILS}}\n\nIf you have any questions, contact us at {{DEFAULT_EMAIL}}.\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'visit'
            ),
            'visit_rescheduling' => array(
                'group' => 'Jobs',
                'title' => 'Visit rescheduling notification',
                'description' => 'Notifies a customer that a visit has been rescheduled when sent',
                'event_key' => 'visit.rescheduled',
                'type' => 'template',
                'subject' => 'Your visit has been rescheduled - {{APPOINTMENT_DATE}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nYour upcoming visit has been rescheduled.\n\n{{VISIT_RESCHEDULE_DETAILS}}\n\n{{WORK_DETAILS}}\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'visit'
            ),
            'visit_reminder' => array(
                'group' => 'Jobs',
                'title' => 'Visit reminder',
                'description' => 'Automatically notifies a customer about an upcoming visit',
                'event_key' => 'visit.reminder',
                'type' => 'reminder',
                'toggle' => 1,
                'subject' => 'Scheduled visit reminder by {{COMPANY_NAME}} - {{APPOINTMENT_DATE}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nJust a friendly reminder that we have an upcoming appointment.\n\n{{WORK_DETAILS}}\n\nIf you have any questions or concerns, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nCLICK LINK BELOW TO VIEW YOUR APPOINTMENT:\n\n{{VISIT_CONFIRMATION_LINK}}\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'visit',
                'schedules' => array(
                    array('amount' => 1, 'offset_type' => 'hour_before', 'time_of_day' => null),
                    array('amount' => 1, 'offset_type' => 'day_before', 'time_of_day' => '15:30:00')
                )
            ),
            'job_checklist' => array(
                'group' => 'Jobs',
                'title' => 'Checklist',
                'description' => 'A copy of a checklist used during a visit when sent',
                'event_key' => 'job.checklist_sent',
                'type' => 'template',
                'subject' => '{{JOB_FORM_NAME}} from {{COMPANY_NAME}}',
                'body' => "Hello {{CLIENT_NAME}},\n\nThe {{JOB_FORM_NAME}} document related to your appointment is now available. You can save or print a copy to keep for your records.\n\nIf you have any questions or concerns, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nSincerely,\n\n{{COMPANY_NAME}}",
                'context' => 'checklist'
            ),
            'chemical_treatment' => array(
                'group' => 'Jobs',
                'title' => 'Chemical treatment',
                'description' => 'A record of chemicals used during a job when sent',
                'event_key' => 'job.chemical_treatment_sent',
                'type' => 'template',
                'subject' => 'Chemical treatment record from {{COMPANY_NAME}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nA chemical treatment record for job {{JOB_NUMBER}} is ready.\n\n{{WORK_DETAILS}}\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'job'
            ),
            'job_follow_up' => array(
                'group' => 'Jobs',
                'title' => 'Job follow-up',
                'description' => 'Automatically requests feedback after a job is completed with an optional survey after closing a job',
                'event_key' => 'job.follow_up',
                'type' => 'feature',
                'toggle' => 1,
                'subject' => 'How did we do on {{JOB_TITLE}}?',
                'body' => "Hi {{CLIENT_NAME}},\n\nThank you for choosing {{COMPANY_NAME}}. We’d appreciate your feedback on the work we completed.\n\n{{JOB_REVIEW_LINK}}\n\nThanks,\n{{COMPANY_NAME}}",
                'context' => 'job'
            ),
            'invoice_new' => array(
                'group' => 'Invoices',
                'title' => 'New invoice',
                'description' => 'Notifies a customer about a new invoice when sent',
                'event_key' => 'invoice.sent',
                'type' => 'template',
                'subject' => 'Invoice from {{COMPANY_NAME}} - {{INVOICE_SUBJECT}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nThank you for choosing to work with us.\n\nJust a quick note to let you know your invoice is now available.\n\nPlease let us know if you have any questions by emailing us at {{DEFAULT_EMAIL}}.",
                'context' => 'invoice'
            ),
            'payment_receipt' => array(
                'group' => 'Invoices',
                'title' => 'Payment & deposit receipts',
                'description' => 'Automatic or manual receipts when a payment is made by a customer',
                'event_key' => 'payment.receipt_sent',
                'type' => 'template',
                'subject' => 'Payment receipt from {{COMPANY_NAME}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nWe received your payment of {{PAYMENT_AMOUNT}}.\n\nReceipt: {{PAYMENT_NUMBER}}\nInvoice: {{INVOICE_NUMBER}}\n\nThank you,\n{{COMPANY_NAME}}",
                'context' => 'invoice'
            ),
            'statement' => array(
                'group' => 'General',
                'title' => 'Statements',
                'description' => 'A copy of a customer’s billing history when sent',
                'event_key' => 'statement.sent',
                'type' => 'template',
                'subject' => 'Statement from {{COMPANY_NAME}} - {{CURRENT_DATE}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nPlease find attached a detailed statement of your payments, invoices, and account balance as of {{CURRENT_DATE}}.\n\nIf you have any questions or concerns regarding this statement, please don't hesitate to get in touch with us at {{DEFAULT_EMAIL}}.\n\nThanks for your business.\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'statement'
            ),
            'payment_method_request' => array(
                'group' => 'General',
                'title' => 'Request a payment method on file',
                'description' => 'Requests that a customer save a payment method for future billing when sent',
                'event_key' => 'payment_method.requested',
                'type' => 'template',
                'subject' => 'Payment method request from {{COMPANY_NAME}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nPlease use the secure link below to save a payment method for future billing.\n\n{{PAYMENT_METHOD_LINK}}\n\nThank you,\n{{COMPANY_NAME}}",
                'context' => 'general'
            ),
            'signed_documents' => array(
                'group' => 'General',
                'title' => 'Signed documents',
                'description' => 'Provides a copy of signed documents to a customer when sent',
                'event_key' => 'signed_document.sent',
                'type' => 'template',
                'subject' => 'Signed document from {{COMPANY_NAME}}',
                'body' => "Hi {{CLIENT_NAME}},\n\nA signed document is attached for your records.\n\nSincerely,\n{{COMPANY_NAME}}",
                'context' => 'general'
            )
        );
    }
}

if (!function_exists('es_variable_groups')) {
    function es_variable_groups($context)
    {
        $base = array(
            'General' => array(
                array('label' => 'Current Date', 'token' => '{{CURRENT_DATE}}')
            ),
            'Customer' => array(
                array('label' => 'Account Balance', 'token' => '{{ACCOUNT_BALANCE}}'),
                array('label' => 'Company Name', 'token' => '{{CLIENT_COMPANY_NAME}}'),
                array('label' => 'Name', 'token' => '{{CLIENT_NAME}}'),
                array('label' => 'First Name', 'token' => '{{CLIENT_FIRST_NAME}}'),
                array('label' => 'Last Name', 'token' => '{{CLIENT_LAST_NAME}}'),
                array('label' => 'Title', 'token' => '{{CLIENT_TITLE}}')
            ),
            'Your Branding' => array(
                array('label' => 'Company Name', 'token' => '{{COMPANY_NAME}}'),
                array('label' => 'Contact Email', 'token' => '{{DEFAULT_EMAIL}}'),
                array('label' => 'Phone Number', 'token' => '{{DEFAULT_PHONE}}')
            )
        );

        $extra = array();
        if ($context === 'request') {
            $extra['Work Request'] = array(
                array('label' => 'Address', 'token' => '{{REQUEST_ADDRESS}}'),
                array('label' => 'Number', 'token' => '{{REQUEST_NUMBER}}'),
                array('label' => 'Preferred Date', 'token' => '{{REQUEST_PREFERRED_DATE}}'),
                array('label' => 'Service', 'token' => '{{REQUEST_SERVICE}}')
            );
        } elseif ($context === 'assessment') {
            $extra['Assessment'] = array(
                array('label' => 'Assigned Users', 'token' => '{{ASSIGNED_USERS}}'),
                array('label' => 'Appointment Link', 'token' => '{{VISIT_CONFIRMATION_LINK}}'),
                array('label' => 'Date and Time', 'token' => '{{VISIT_DETAILS}}'),
                array('label' => 'Summary', 'token' => '{{ASSESSMENT_SUMMARY}}'),
                array('label' => 'Service Address', 'token' => '{{SERVICE_ADDRESS}}'),
                array('label' => 'Time', 'token' => '{{APPOINTMENT_TIME}}'),
                array('label' => 'Appointment Date', 'token' => '{{APPOINTMENT_DATE}}')
            );
        } elseif ($context === 'visit') {
            $extra['Visit'] = array(
                array('label' => 'Appointment Date', 'token' => '{{APPOINTMENT_DATE}}'),
                array('label' => 'Appointment Link', 'token' => '{{VISIT_CONFIRMATION_LINK}}'),
                array('label' => 'Arrival Window', 'token' => '{{ARRIVAL_WINDOW}}'),
                array('label' => 'Assigned Users', 'token' => '{{ASSIGNED_USERS}}'),
                array('label' => 'Date and Time', 'token' => '{{VISIT_DETAILS}}'),
                array('label' => 'Service Address', 'token' => '{{SERVICE_ADDRESS}}'),
                array('label' => 'Summary', 'token' => '{{VISIT_SUMMARY}}'),
                array('label' => 'Time', 'token' => '{{APPOINTMENT_TIME}}'),
                array('label' => 'Visit Reschedule Details', 'token' => '{{VISIT_RESCHEDULE_DETAILS}}'),
                array('label' => 'Work Details', 'token' => '{{WORK_DETAILS}}')
            );
            $extra['Job'] = array(
                array('label' => 'Job Title', 'token' => '{{JOB_TITLE}}'),
                array('label' => 'Number', 'token' => '{{JOB_NUMBER}}')
            );
        } elseif ($context === 'quote') {
            $extra['Quote'] = array(
                array('label' => 'Deposit Amount', 'token' => '{{QUOTE_DEPOSIT_AMOUNT}}'),
                array('label' => 'Job Title', 'token' => '{{JOB_TITLE}}'),
                array('label' => 'Discount Amount', 'token' => '{{QUOTE_DISCOUNT_AMOUNT}}'),
                array('label' => 'Address', 'token' => '{{QUOTE_ADDRESS}}'),
                array('label' => 'Number', 'token' => '{{QUOTE_NUMBER}}'),
                array('label' => 'Sent Date', 'token' => '{{QUOTE_SENT_DATE}}'),
                array('label' => 'Consumer Financing', 'token' => '{{QUOTE_CONSUMER_FINANCING}}'),
                array('label' => 'Total', 'token' => '{{QUOTE_TOTAL}}'),
                array('label' => 'Quote Label', 'token' => '{{QUOTE_LABEL}}')
            );
        } elseif ($context === 'invoice' || $context === 'statement') {
            $extra['Invoice'] = array(
                array('label' => 'Invoice Number', 'token' => '{{INVOICE_NUMBER}}'),
                array('label' => 'Invoice Subject', 'token' => '{{INVOICE_SUBJECT}}'),
                array('label' => 'Issue Date', 'token' => '{{INVOICE_DATE}}'),
                array('label' => 'Due Date', 'token' => '{{INVOICE_DUE_DATE}}'),
                array('label' => 'Total', 'token' => '{{INVOICE_TOTAL}}'),
                array('label' => 'Balance', 'token' => '{{INVOICE_BALANCE}}'),
                array('label' => 'Payment Amount', 'token' => '{{PAYMENT_AMOUNT}}'),
                array('label' => 'Payment Number', 'token' => '{{PAYMENT_NUMBER}}')
            );
        } elseif ($context === 'checklist') {
            $extra['Job'] = array(
                array('label' => 'Checklist Name', 'token' => '{{JOB_FORM_NAME}}'),
                array('label' => 'Job Title', 'token' => '{{JOB_TITLE}}'),
                array('label' => 'Job Number', 'token' => '{{JOB_NUMBER}}')
            );
        } elseif ($context === 'job') {
            $extra['Job'] = array(
                array('label' => 'Job Title', 'token' => '{{JOB_TITLE}}'),
                array('label' => 'Number', 'token' => '{{JOB_NUMBER}}'),
                array('label' => 'Work Details', 'token' => '{{WORK_DETAILS}}'),
                array('label' => 'Review Link', 'token' => '{{JOB_REVIEW_LINK}}')
            );
        } else {
            $extra['General Details'] = array(
                array('label' => 'Payment Method Link', 'token' => '{{PAYMENT_METHOD_LINK}}')
            );
        }

        return array_merge($base, $extra);
    }
}
