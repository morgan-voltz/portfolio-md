using Portfolio.Application.Ports;

namespace Portfolio.Infrastructure.Tests.Support;

/// <summary>
/// The two outcomes of a <see cref="Fetch{T}"/>, examined by pattern matching,
/// never by <c>GetType()</c>, which gives the type of the union (ADR-026, point 6).
/// </summary>
/// <remarks>
/// <c>where T : notnull</c>: a type pattern such as <c>T value</c> never matches
/// <see langword="null"/>, so for a <c>T</c> that admits it the compiler would
/// ask for a third arm (CS8655). Every <c>Fetch</c> of the client carries a
/// value that cannot be <see langword="null"/>.
/// </remarks>
internal static class FetchAssertions
{
    public static T AssertFetched<T>(this Fetch<T> fetch)
        where T : notnull => fetch switch
        {
            SourceUnavailable unavailable => throw new InvalidOperationException($"Expected an answer, the plugin was unavailable: {unavailable.Reason}"),
            T value => value,
        };

    /// <summary>The reason the plugin was unavailable.</summary>
    public static string AssertUnavailable<T>(this Fetch<T> fetch)
        where T : notnull => fetch switch
        {
            SourceUnavailable unavailable => unavailable.Reason,
            T => throw new InvalidOperationException("Expected the plugin to be unavailable, it answered."),
        };
}
