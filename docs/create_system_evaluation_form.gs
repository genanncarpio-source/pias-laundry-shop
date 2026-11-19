/**
 * Creates the Pia's Laundry Shop system evaluation survey in Google Forms.
 * Run createSystemEvaluationForm() once from script.google.com.
 * The execution log contains the edit and respondent URLs.
 */
function createSystemEvaluationForm() {
  var form = FormApp.create("Pia's Laundry Shop POS and Management System Evaluation");

  form.setDescription(
    "Thank you for trying Pia's Laundry Shop Point-of-Sale and Management System. " +
    "This survey asks about your experience with the system's ordering, order tracking, " +
    "and shop management features. Responses will be used to evaluate and improve the " +
    "system. Do not include passwords, payment details, or other sensitive personal " +
    "information. Participation is voluntary."
  );
  form.setConfirmationMessage("Thank you for sharing your feedback. Your response has been recorded.");
  form.setCollectEmail(true); // Adds Google's required respondent email collection.
  form.setLimitOneResponsePerUser(false);
  // Do not call setRequireLogin(): Google has deprecated it and it can throw
  // "This operation is not supported". Email collection is enabled above.
  form.setProgressBar(true);

  form.addSectionHeaderItem().setTitle("About you");
  form.addMultipleChoiceItem()
    .setTitle("Which best describes your role?")
    .setChoiceValues(["Shop owner / administrator", "Cashier / staff", "Customer", "Other"])
    .setRequired(true);
  form.addMultipleChoiceItem()
    .setTitle("How often have you used the system?")
    .setChoiceValues(["This is my first time", "Occasionally", "Weekly", "Daily"])
    .setRequired(true);
  form.addCheckboxItem()
    .setTitle("Which parts of the system did you use?")
    .setChoiceValues([
      "Point of Sale / placing an order", "Order status tracking",
      "Customer records or account", "Services and pricing", "Payments or receipts",
      "Reports / dashboard", "Inventory / expenses", "Mobile app / pickup or delivery", "Other"
    ]);

  form.addPageBreakItem().setTitle("Rate your experience");
  [
    "The system is easy to learn.",
    "The screens and instructions are clear.",
    "I can find the feature I need without difficulty.",
    "The system responds reliably when I use it.",
    "The information shown (such as order details, status, and prices) is easy to understand.",
    "The system helps reduce mistakes or repeated work.",
    "I feel confident using the system for my task.",
    "Overall, I am satisfied with the system."
  ].forEach(function(title) {
    addScaleQuestion_(form, title, "Strongly disagree", "Strongly agree");
  });

  var roleRouting = form.addMultipleChoiceItem()
    .setTitle("Which role-specific questions should you answer?")
    .setRequired(true);

  var staffPage = form.addPageBreakItem().setTitle("Questions for shop owner, administrator, or staff");
  addScaleQuestion_(form, "How easy is it to record a sale or customer order?", "Very difficult", "Very easy");
  addScaleQuestion_(form, "How easy is it to update and find an order's current status?", "Very difficult", "Very easy");
  addScaleQuestion_(form, "How useful are receipts and payment records?", "Not useful", "Very useful");
  addScaleQuestion_(form, "How useful are customer, service, and pricing records?", "Not useful", "Very useful");
  addScaleQuestion_(form, "If you used management tools, how useful are the reports, expenses, or inventory features?", "Not useful", "Very useful");
  form.addParagraphTextItem().setTitle("Which task, if any, is still difficult or takes too long?");

  var customerPage = form.addPageBreakItem().setTitle("Questions for customers");
  addScaleQuestion_(form, "How easy was it to place or request an order?", "Very difficult", "Very easy");
  addScaleQuestion_(form, "How easy was it to check your order's status or receive updates?", "Very difficult", "Very easy");
  addScaleQuestion_(form, "Were the service options, prices, and order information clear?", "Not at all clear", "Very clear");
  addScaleQuestion_(form, "How satisfied were you with the ordering or pickup / delivery experience?", "Very dissatisfied", "Very satisfied");
  form.addParagraphTextItem().setTitle("What would make ordering or tracking easier for you?");

  var suggestionsPage = form.addPageBreakItem().setTitle("Suggestions");
  staffPage.setGoToPage(suggestionsPage);
  customerPage.setGoToPage(suggestionsPage);
  roleRouting.setChoices([
    roleRouting.createChoice("Shop owner / administrator", staffPage),
    roleRouting.createChoice("Cashier / staff", staffPage),
    roleRouting.createChoice("Customer", customerPage),
    roleRouting.createChoice("Other", suggestionsPage)
  ]);

  form.addParagraphTextItem().setTitle("What did you like most about the system?");
  form.addParagraphTextItem().setTitle("Did you encounter any problem or error? If yes, please describe what happened.");
  form.addParagraphTextItem().setTitle("What feature or improvement would you most like to see?");
  form.addParagraphTextItem().setTitle("Any other comments?");

  Logger.log("Form created. Edit URL: " + form.getEditUrl());
  Logger.log("Respondent URL: " + form.getPublishedUrl());
}

function addScaleQuestion_(form, title, lowLabel, highLabel) {
  form.addScaleItem()
    .setTitle(title)
    .setBounds(1, 5)
    .setLabels(lowLabel, highLabel)
    .setRequired(true);
}
