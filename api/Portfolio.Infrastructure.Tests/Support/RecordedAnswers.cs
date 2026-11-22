using System.Net;
using System.Text;

namespace Portfolio.Infrastructure.Tests.Support;

/// <summary>The answers of the plugin recorded in <c>Recorded/</c> (see its README), as HTTP responses.</summary>
internal static class RecordedAnswers
{
    private static readonly string Folder = Path.Combine(AppContext.BaseDirectory, "Recorded");

    /// <summary>A new response carrying the answer recorded under <paramref name="name"/>.</summary>
    public static HttpResponseMessage Load(string name) =>
        Response(File.ReadAllLines(Path.Combine(Folder, name + ".headers")), Body(name));

    /// <summary>The body recorded under <paramref name="name"/>: JSON, or the HTML of the firewall page.</summary>
    public static string Body(string name) =>
        File.ReadAllText(new[] { ".json", ".html" }.Select(extension => Path.Combine(Folder, name + extension)).First(File.Exists));

    /// <summary>
    /// A response from a status line and headers written as curl writes them
    /// (<c>HTTP/2 200</c>, then one <c>name: value</c> per line), and a body.
    /// </summary>
    public static HttpResponseMessage Response(IEnumerable<string> head, string body)
    {
        var lines = head.Select(line => line.TrimEnd('\r')).Where(line => line.Length > 0).ToArray();
        var status = int.Parse(lines[0].Split(' ', StringSplitOptions.RemoveEmptyEntries)[1]);

        // ByteArrayContent, unlike StringContent, adds no content type of its own: only the recorded one.
        var response = new HttpResponseMessage((HttpStatusCode)status) { Content = new ByteArrayContent(Encoding.UTF8.GetBytes(body)) };
        foreach (var line in lines.Skip(1))
        {
            var colon = line.IndexOf(':');
            var name = line[..colon].Trim();
            var value = line[(colon + 1)..].Trim();
            if (!response.Headers.TryAddWithoutValidation(name, value))
            {
                response.Content.Headers.TryAddWithoutValidation(name, value);
            }
        }

        return response;
    }

    /// <summary>A successful JSON answer of a list, with the totals the plugin puts in its headers.</summary>
    public static HttpResponseMessage List(string body, int total, int totalPages) =>
        Response(["HTTP/2 200", "content-type: application/json; charset=UTF-8", $"x-wp-total: {total}", $"x-wp-totalpages: {totalPages}"], body);
}
