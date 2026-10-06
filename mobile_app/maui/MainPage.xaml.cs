using System.Globalization;

namespace LaundryCustomerMaui;

public partial class MainPage : ContentPage
{
    private readonly ApiClient _api = new();
    private Customer? _customer;
    private List<ServiceItem> _services = [];
    private List<Ticket> _tickets = [];
    private readonly Dictionary<int, Entry> _quantities = [];
    private readonly VerticalStackLayout _content = new() { Spacing = 14, Padding = new Thickness(20) };
    private bool _registering;
    private bool _busy;

    public MainPage()
    {
        InitializeComponent();
        Root.Children.Add(new ScrollView { Content = _content });
        ShowLoading();
        _ = RestoreSessionAsync();
    }

    private void ShowLoading()
    {
        _content.Clear();
        _content.Add(new ActivityIndicator { IsRunning = true, Color = Color.FromArgb("#146C63"), VerticalOptions = LayoutOptions.Center });
    }

    private async Task RestoreSessionAsync()
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(await _api.GetTokenAsync()))
                _customer = await _api.GetMeAsync();
            if (_customer is null) ShowAuth(); else await LoadHomeAsync();
        }
        catch (Exception ex)
        {
            await _api.ClearTokenAsync();
            ShowAuth(ex.Message);
        }
    }

    private void ShowAuth(string? error = null)
    {
        _content.Clear();
        _content.Add(new Label { Text = "Pia's Laundry Shop", FontSize = 28, FontAttributes = FontAttributes.Bold, TextColor = Color.FromArgb("#CF2C73"), HorizontalTextAlignment = TextAlignment.Center });
        _content.Add(new Label { Text = _registering ? "Create a customer account to request services." : "Sign in to request services and track your laundry.", HorizontalTextAlignment = TextAlignment.Center });

        var apiUrl = new Entry { Text = _api.BaseUrl, Placeholder = "API address", Keyboard = Keyboard.Url, ClearButtonVisibility = ClearButtonVisibility.WhileEditing };
        _content.Add(new Label { Text = "Shop API address", FontAttributes = FontAttributes.Bold });
        _content.Add(apiUrl);
        _content.Add(new Label { Text = "Emulator: http://10.0.2.2/laundry-pos/api | Phone: use your computer's LAN IP", FontSize = 12, TextColor = Colors.Gray });

        var name = new Entry { Placeholder = "Full name", IsVisible = _registering };
        var phone = new Entry { Placeholder = "Phone number", Keyboard = Keyboard.Telephone, IsVisible = _registering };
        var email = new Entry { Placeholder = "Email address", Keyboard = Keyboard.Email };
        var password = new Entry { Placeholder = _registering ? "Password (at least 10 characters)" : "Password", IsPassword = true };
        if (_registering) { _content.Add(name); _content.Add(phone); }
        _content.Add(email);
        _content.Add(password);

        var errorLabel = new Label { Text = error, TextColor = Colors.DarkRed, IsVisible = !string.IsNullOrWhiteSpace(error) };
        _content.Add(errorLabel);
        var submit = new Button { Text = _registering ? "Create account" : "Sign in", BackgroundColor = Color.FromArgb("#CF2C73"), TextColor = Colors.White };
        var toggle = new Button { Text = _registering ? "I already have an account" : "Create a customer account", BackgroundColor = Colors.Transparent, TextColor = Color.FromArgb("#A61957") };
        toggle.Clicked += (_, _) => { _registering = !_registering; ShowAuth(); };
        submit.Clicked += async (_, _) =>
        {
            if (_busy) return;
            if (string.IsNullOrWhiteSpace(email.Text) || string.IsNullOrWhiteSpace(password.Text) ||
                (_registering && (string.IsNullOrWhiteSpace(name.Text) || string.IsNullOrWhiteSpace(phone.Text) || password.Text.Length < 10)))
            { errorLabel.Text = _registering ? "Enter your name, phone, email, and a password of at least 10 characters." : "Enter your email and password."; errorLabel.IsVisible = true; return; }
            try
            {
                _busy = true; submit.IsEnabled = false; errorLabel.IsVisible = false;
                _api.SaveBaseUrl(apiUrl.Text ?? "");
                _customer = _registering
                    ? await _api.RegisterAsync(name.Text!.Trim(), phone.Text!.Trim(), email.Text!.Trim(), password.Text!)
                    : await _api.LoginAsync(email.Text!.Trim(), password.Text!);
                await LoadHomeAsync();
            }
            catch (Exception ex) { errorLabel.Text = ex.Message; errorLabel.IsVisible = true; }
            finally { _busy = false; submit.IsEnabled = true; }
        };
        _content.Add(submit);
        _content.Add(toggle);
    }

    private async Task LoadHomeAsync(string? notice = null)
    {
        try
        {
            var servicesTask = _api.GetServicesAsync();
            var ticketsTask = _api.GetTicketsAsync();
            await Task.WhenAll(servicesTask, ticketsTask);
            _services = await servicesTask;
            _tickets = await ticketsTask;
            ShowHome(notice);
        }
        catch (Exception ex)
        {
            if (await _api.GetTokenAsync() is null) { _customer = null; ShowAuth(ex.Message); }
            else ShowHome(ex.Message);
        }
    }

    private void ShowHome(string? notice = null)
    {
        _content.Clear();
        _content.Add(new Label { Text = $"Hello, {_customer?.Name ?? "Customer"}", FontSize = 24, FontAttributes = FontAttributes.Bold });
        _content.Add(new Label { Text = "Choose services to send a request to the shop." });
        if (!string.IsNullOrWhiteSpace(notice)) _content.Add(new Label { Text = notice, TextColor = Colors.DarkRed });

        _quantities.Clear();
        var serviceCard = new VerticalStackLayout { Spacing = 10, Padding = 16, BackgroundColor = Colors.White };
        serviceCard.Add(new Label { Text = "Request a service", FontSize = 20, FontAttributes = FontAttributes.Bold });
        if (_services.Count == 0) serviceCard.Add(new Label { Text = "No services are currently available." });
        foreach (var service in _services)
        {
            serviceCard.Add(new Label { Text = service.Name, FontAttributes = FontAttributes.Bold });
            serviceCard.Add(new Label { Text = $"{service.Category} – ₱{service.Price.ToString("0.00", CultureInfo.InvariantCulture)} / {service.Unit}", FontSize = 13 });
            var quantity = new Entry { Placeholder = $"Quantity ({service.Unit})", Keyboard = Keyboard.Numeric };
            _quantities[service.Id] = quantity;
            serviceCard.Add(quantity);
        }

        var pickup = new DatePicker { MinimumDate = DateTime.Today, Date = DateTime.Today.AddDays(1) };
        var time = new TimePicker { Time = new TimeSpan(9, 0, 0) };
        var pickupEnabled = new Switch { IsToggled = false };
        var notes = new Editor { Placeholder = "Notes (optional)", AutoSize = EditorAutoSizeOption.TextChanges, MaxLength = 255, HeightRequest = 90 };
        serviceCard.Add(new HorizontalStackLayout { Spacing = 8, Children = { pickupEnabled, new Label { Text = "Suggest a pickup time", VerticalOptions = LayoutOptions.Center } } });
        pickup.Date = DateTime.Today.AddDays(1);
        pickup.IsEnabled = time.IsEnabled = false;
        pickupEnabled.Toggled += (_, e) => pickup.IsEnabled = time.IsEnabled = e.Value;
        serviceCard.Add(pickup);
        serviceCard.Add(time);
        serviceCard.Add(notes);
        var estimate = new Label { Text = "Estimated total: ₱0.00", HorizontalTextAlignment = TextAlignment.End, FontAttributes = FontAttributes.Bold };
        foreach (var entry in _quantities.Values) entry.TextChanged += (_, _) => estimate.Text = $"Estimated total: ₱{Estimate().ToString("0.00", CultureInfo.InvariantCulture)}";
        serviceCard.Add(estimate);

        var send = new Button { Text = "Submit service request", BackgroundColor = Color.FromArgb("#CF2C73"), TextColor = Colors.White };
        send.Clicked += async (_, _) =>
        {
            var requested = _services.Select(s => new { service_id = s.Id, quantity = ParseQuantity(_quantities[s.Id].Text) }).Where(x => x.quantity > 0).ToArray();
            if (requested.Length == 0) { await DisplayAlert("Choose a service", "Enter a quantity greater than zero.", "OK"); return; }
            try
            {
                send.IsEnabled = false;
                var expectedPickup = pickupEnabled.IsToggled
                    ? pickup.Date.Add(time.Time).ToUniversalTime().ToString("O", CultureInfo.InvariantCulture)
                    : null;
                var created = await _api.CreateTicketAsync(new { services = requested, expected_pickup = expectedPickup, notes = notes.Text?.Trim() ?? "" });
                foreach (var entry in _quantities.Values) entry.Text = "";
                notes.Text = "";
                await LoadHomeAsync($"Request submitted. Ticket {created.Ticket?.TicketNumber ?? "created"}.");
            }
            catch (Exception ex) { await DisplayAlert("Request failed", ex.Message, "OK"); }
            finally { send.IsEnabled = true; }
        };
        serviceCard.Add(send);
        serviceCard.Add(new Label { Text = "This is an estimate. The shop will confirm your request and collect payment at the branch.", FontSize = 12, TextColor = Colors.Gray });
        _content.Add(new Border { Stroke = Color.FromArgb("#D8E2DF"), StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle { CornerRadius = 12 }, Content = serviceCard, Padding = 0 });

        var ticketHeader = new HorizontalStackLayout { Spacing = 10 };
        ticketHeader.Add(new Label { Text = "My service tickets", FontSize = 20, FontAttributes = FontAttributes.Bold, VerticalOptions = LayoutOptions.Center, HorizontalOptions = LayoutOptions.StartAndExpand });
        var refresh = new Button { Text = "Refresh" };
        refresh.Clicked += async (_, _) => await LoadHomeAsync();
        ticketHeader.Add(refresh);
        _content.Add(ticketHeader);
        if (_tickets.Count == 0) _content.Add(new Label { Text = "You have no service tickets yet." });
        foreach (var ticket in _tickets)
        {
            var lines = string.Join(", ", ticket.Services.Select(s => $"{s.ServiceName} ({s.Quantity} {s.Unit})"));
            var card = new VerticalStackLayout { Spacing = 5, Padding = 14, BackgroundColor = Colors.White };
            card.Add(new Label { Text = $"{ticket.OrderNumber}  •  {ticket.Status.Replace('_', ' ')}", FontAttributes = FontAttributes.Bold });
            card.Add(new Label { Text = ticket.CreatedAt });
            if (lines.Length > 0) card.Add(new Label { Text = lines });
            card.Add(new Label { Text = $"Estimated total: ₱{ticket.Total:0.00}" });
            card.Add(new Label { Text = $"Balance due: ₱{ticket.BalanceDue:0.00}" });
            _content.Add(new Border { Stroke = Color.FromArgb("#D8E2DF"), StrokeShape = new Microsoft.Maui.Controls.Shapes.RoundRectangle { CornerRadius = 12 }, Content = card, Padding = 0 });
        }

        var signOut = new Button { Text = "Sign out", TextColor = Color.FromArgb("#A61957"), BackgroundColor = Colors.Transparent };
        signOut.Clicked += async (_, _) =>
        {
            try { await _api.SignOutAsync(); } catch { await _api.ClearTokenAsync(); }
            _customer = null;
            ShowAuth();
        };
        _content.Add(signOut);
    }

    private decimal Estimate() => _services.Sum(s => s.Price * ParseQuantity(_quantities.TryGetValue(s.Id, out var e) ? e.Text : ""));

    private static decimal ParseQuantity(string? text) => decimal.TryParse(text, NumberStyles.Number, CultureInfo.CurrentCulture, out var value) && value > 0 ? value : 0;
}
