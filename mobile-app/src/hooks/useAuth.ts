import { useState, useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { login, logout } from '../store/authSlice';

const useAuth = () => {
    const dispatch = useDispatch();
    const authState = useSelector((state) => state.auth);
    const [error, setError] = useState(null);

    const loginUser = (code, teamName) => {
        // Implement your login logic here
        // For example, validate the code and teamName, then dispatch login action
        if (code && teamName) {
            dispatch(login({ code, teamName }));
        } else {
            setError('Invalid code or team name');
        }
    };

    const logoutUser = () => {
        dispatch(logout());
    };

    useEffect(() => {
        // Handle any side effects related to authentication state changes
        if (authState.isLoggedIn) {
            setError(null);
        }
    }, [authState]);

    return {
        isLoggedIn: authState.isLoggedIn,
        loginUser,
        logoutUser,
        error,
    };
};

export default useAuth;