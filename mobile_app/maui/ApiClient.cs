using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace LaundryCustomerMaui;

internal sealed class ApiClient
{
    private const string TokenKey = "customer_api_token";
    private const string ApiUrlKey = "customer_api_url";
    private const string DefaultApiUrl = "http://10.0.2.2/laundry-pos/api";
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(20) };
    private static readonly JsonSerializerOptions JsonOptions = new() { PropertyNameCaseInsensitive = true };

    public string BaseUrl => Preferences.Default.Get(ApiUrlKey, DefaultApiUrl).TrimEnd('/');

    public void SaveBaseUrl(string url)
    {
        if (!Uri.TryCreate(url.Trim().TrimEnd('/'), UriKind.Absolute, out var uri) ||
            (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps))
            throw new ArgumentException("Enter a valid URL beginning with http:// or https://");

        Preferences.Default.Set(ApiUrlKey, uri.ToString().TrimEnd('/'));
    }

    public Task<string?> GetTokenAsync() => SecureStorage.Default.GetAsync(TokenKey);

    public Task SaveTokenAsync(string token) => SecureStorage.Default.SetAsync(TokenKey, token);

    public Task ClearTokenAsync() => SecureStorage.Default.Remove(TokenKey);

    public Task<Customer> LoginAsync(string email, string password) =>
        AuthenticateAsync("login.php", new { email, password });

    public Task<Customer> RegisterAsync(string name, string phone, string email, string password) =>
        AuthenticateAsync("register.php", new { name, phone, email, password });

    private async Task<Customer> AuthenticateAsync(string endpoint, object body)
    {
        var response = await SendAsync<AuthResponse>(endpoint, HttpMethod.Post, body);
        if (string.IsNullOrWhiteSpace(response.Token) || response.Customer is null)
            throw new ApiException("The shop returned an incomplete sign-in response.");

        await SaveTokenAsync(response.Token);
        return response.Customer;
    }

    public async Task<Customer> GetMeAsync()
    {
        var response = await SendAsync<CustomerEnvelope>("me.php", HttpMethod.Get, authenticated: true);
        return response.Customer ?? throw new ApiException("The shop returned an incomplete profile response.");
    }

    public async Task<List<ServiceItem>> GetServicesAsync()
    {
        var response = await SendAsync<ServicesResponse>("services.php", HttpMethod.Get);
        return response.Services ?? [];
    }

    public async Task<List<Ticket>> GetTicketsAsync()
    {
        var response = await SendAsync<TicketsResponse>("my_tickets.php", HttpMethod.Get, authenticated: true);
        return response.Tickets ?? [];
    }

    public Task<TicketCreated> CreateTicketAsync(object body) => SendAsync<TicketCreated>("tickets.php", HttpMethod.Post, body, authenticated: true);

    public async Task SignOutAsync()
    {
        try { await SendAsync<MessageResponse>("logout.php", HttpMethod.Post, new { }, authenticated: true); }
        finally { await ClearTokenAsync(); }
    }

    private async Task<T> SendAsync<T>(string endpoint, HttpMethod method, object? body = null, bool authenticated = false)
    {
        using var request = new HttpRequestMessage(method, $"{BaseUrl}/{endpoint}");
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        if (body is not null)
            request.Content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json");

        if (authenticated)
        {
            var token = await GetTokenAsync();
            if (!string.IsNullOrWhiteSpace(token)) request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }

        HttpResponseMessage response;
        try { response = await Http.SendAsync(request); }
        catch (TaskCanceledException) { throw new ApiException("The shop did not respond in time. Check the network and API address."); }
        catch (HttpRequestException) { throw new ApiException("Could not connect to the shop. Check the API address, XAMPP, and network connection."); }

        using (response)
        {
            var text = await response.Content.ReadAsStringAsync();
            ApiEnvelope? envelope;
            try { envelope = JsonSerializer.Deserialize<ApiEnvelope>(text, JsonOptions); }
            catch (JsonException) { throw new ApiException("The shop server returned an unreadable response."); }

            if (!response.IsSuccessStatusCode || envelope?.Success != true)
            {
                if (response.StatusCode == System.Net.HttpStatusCode.Unauthorized && authenticated)
                    await ClearTokenAsync();
                throw new ApiException(envelope?.Error ?? "The request could not be completed.");
            }

            try
            {
                return JsonSerializer.Deserialize<T>(text, JsonOptions)
                    ?? throw new ApiException("The shop server returned an empty response.");
            }
            catch (JsonException) { throw new ApiException("The shop server returned an unreadable response."); }
        }
    }
}

internal sealed class ApiException(string message) : Exception(message);

internal class ApiEnvelope
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("error")] public string? Error { get; set; }
}

internal sealed class AuthResponse : ApiEnvelope
{
    [JsonPropertyName("token")] public string? Token { get; set; }
    [JsonPropertyName("customer")] public Customer? Customer { get; set; }
}

internal sealed class CustomerEnvelope : ApiEnvelope
{
    [JsonPropertyName("customer")] public Customer? Customer { get; set; }
}

internal sealed class ServicesResponse : ApiEnvelope
{
    [JsonPropertyName("services")] public List<ServiceItem>? Services { get; set; }
}

internal sealed class TicketsResponse : ApiEnvelope
{
    [JsonPropertyName("tickets")] public List<Ticket>? Tickets { get; set; }
}

internal sealed class MessageResponse : ApiEnvelope { }

internal sealed class TicketCreated : ApiEnvelope
{
    [JsonPropertyName("ticket")] public CreatedTicket? Ticket { get; set; }
}

internal sealed class CreatedTicket
{
    [JsonPropertyName("ticket_number")] public string? TicketNumber { get; set; }
}

internal sealed class Customer
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "Customer";
    [JsonPropertyName("email")] public string Email { get; set; } = "";
    [JsonPropertyName("phone")] public string Phone { get; set; } = "";
}

internal sealed class ServiceItem
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "Service";
    [JsonPropertyName("category")] public string Category { get; set; } = "Laundry";
    [JsonPropertyName("unit")] public string Unit { get; set; } = "unit";
    [JsonPropertyName("price")] public decimal Price { get; set; }
    [JsonPropertyName("description")] public string? Description { get; set; }
}

internal sealed class Ticket
{
    [JsonPropertyName("order_number")] public string OrderNumber { get; set; } = "Ticket";
    [JsonPropertyName("status")] public string Status { get; set; } = "pending";
    [JsonPropertyName("total")] public decimal Total { get; set; }
    [JsonPropertyName("balance_due")] public decimal BalanceDue { get; set; }
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    [JsonPropertyName("services")] public List<TicketService> Services { get; set; } = [];
}

internal sealed class TicketService
{
    [JsonPropertyName("service_name")] public string ServiceName { get; set; } = "Service";
    [JsonPropertyName("unit")] public string Unit { get; set; } = "unit";
    [JsonPropertyName("quantity")] public decimal Quantity { get; set; }
}
