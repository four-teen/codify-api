"""Parse submitted text only. Never evaluate, import, or execute student code."""
import ast
import json
import sys

code = json.load(sys.stdin).get("code", "")
result = {"syntax_valid": False, "syntax_error": None, "features": [], "formatting": []}
try:
    tree = ast.parse(code)
    result["syntax_valid"] = True
    features = set()
    types = {ast.Assign: "assignment", ast.AnnAssign: "assignment", ast.AugAssign: "assignment", ast.For: "for_loop", ast.While: "while_loop", ast.If: "conditional", ast.FunctionDef: "function", ast.Return: "return", ast.List: "list", ast.Dict: "dictionary", ast.Try: "exception_handling", ast.ListComp: "list_comprehension"}
    for node in ast.walk(tree):
        for node_type, name in types.items():
            if isinstance(node, node_type):
                features.add(name)
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id in {"input", "print", "int", "float", "range", "len"}:
            features.add(node.func.id)
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
            features.add("addition")
        if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Mult):
            features.add("multiplication")
    result["features"] = sorted(features)
except (SyntaxError, ValueError, RecursionError, MemoryError) as error:
    result["syntax_error"] = {"message": getattr(error, "msg", "Unable to parse Python syntax."), "line": getattr(error, "lineno", None)}
for number, line in enumerate(code.splitlines(), 1):
    if "\t" in line[:len(line) - len(line.lstrip())]:
        result["formatting"].append(f"Line {number}: indentation uses tabs.")
    if len(line) > 88:
        result["formatting"].append(f"Line {number}: longer than 88 characters.")
result["formatting"] = result["formatting"][:20]
print(json.dumps(result))
