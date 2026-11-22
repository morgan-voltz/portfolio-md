using System.Reflection;
using System.Text.Json;

namespace Portfolio.Application.Tests;

/// <summary>
/// The dependency rule of the Clean Architecture (ADR-003, ADR-026 point 6):
/// the core depends on nothing but the base class library. Two checks, because
/// each one is blind where the other sees:
/// <list type="bullet">
/// <item>the compiled assembly records the assemblies its code uses, including
/// the nine Microsoft.Extensions libraries that .NET 11 ships in the base
/// framework, which need no package, so that a bare
/// <c>using Microsoft.Extensions.Logging;</c> compiles without any warning;</item>
/// <item>the restore records every package and project the core depends on,
/// used or not, whatever its name and wherever it was declared (the .csproj,
/// Directory.Build.props, a global package reference), including a third-party
/// package whose name starts with "System".</item>
/// </list>
/// </summary>
public sealed class DependencyRuleTests
{
    /// <summary>The names of the base class library: System, System.*, and the two facades.</summary>
    private static readonly string[] AllowedRoots = ["System", "netstandard", "mscorlib"];

    [Fact]
    public void The_core_uses_only_the_base_class_library()
    {
        var core = Assembly.Load(new AssemblyName("Portfolio.Application"));

        Assert.Empty(AssembliesOutsideTheBaseClassLibrary(core));
    }

    [Fact]
    public void The_core_depends_on_no_package_and_no_project()
    {
        var (libraries, frameworks) = RestoredDependencies("Portfolio.Application");

        Assert.Empty(libraries);
        Assert.Equal("Microsoft.NETCore.App", Assert.Single(frameworks));
    }

    [Fact]
    public void The_first_check_sees_an_assembly_outside_the_base_class_library()
    {
        // This test assembly uses xUnit: the check must flag it, or the test of
        // the core would pass whatever the core uses and prove nothing.
        var tests = typeof(DependencyRuleTests).Assembly;

        Assert.Contains(AssembliesOutsideTheBaseClassLibrary(tests), name => name.StartsWith("xunit", StringComparison.Ordinal));
    }

    [Fact]
    public void The_second_check_sees_a_package_and_a_project()
    {
        // This test project depends on an xUnit package and on the core project:
        // the check must list both, for the same reason.
        var (libraries, _) = RestoredDependencies("Portfolio.Application.Tests");

        Assert.Contains(libraries, name => name.StartsWith("xunit.v3.mtp-v2/", StringComparison.Ordinal));
        Assert.Contains(libraries, name => name.StartsWith("Portfolio.Application/", StringComparison.Ordinal));
    }

    private static string[] AssembliesOutsideTheBaseClassLibrary(Assembly assembly) =>
        [.. assembly.GetReferencedAssemblies()
            .Select(reference => reference.Name ?? string.Empty)
            .Where(name => !AllowedRoots.Any(root => name == root || name.StartsWith(root + ".", StringComparison.Ordinal)))];

    /// <summary>
    /// What the restore resolved for a project of the solution, read from its
    /// obj/project.assets.json: its packages and projects ("libraries", as
    /// name/version), and its shared frameworks.
    /// </summary>
    private static (string[] Libraries, string[] Frameworks) RestoredDependencies(string project)
    {
        var path = Path.Combine(SolutionDirectory(), project, "obj", "project.assets.json");
        using var assets = JsonDocument.Parse(File.ReadAllText(path));
        var root = assets.RootElement;

        string[] libraries = [.. root.GetProperty("libraries").EnumerateObject().Select(library => library.Name)];
        string[] frameworks = [.. root.GetProperty("project").GetProperty("frameworks").EnumerateObject()
            .SelectMany(target => target.Value.TryGetProperty("frameworkReferences", out var references)
                ? references.EnumerateObject().Select(reference => reference.Name)
                : Enumerable.Empty<string>())];

        return (libraries, frameworks);
    }

    /// <summary>The folder of Portfolio.slnx, found by walking up from the test binaries.</summary>
    private static string SolutionDirectory()
    {
        for (var directory = new DirectoryInfo(AppContext.BaseDirectory); directory is not null; directory = directory.Parent)
        {
            if (File.Exists(Path.Combine(directory.FullName, "Portfolio.slnx")))
            {
                return directory.FullName;
            }
        }

        throw new InvalidOperationException($"Portfolio.slnx was not found above {AppContext.BaseDirectory}.");
    }
}
